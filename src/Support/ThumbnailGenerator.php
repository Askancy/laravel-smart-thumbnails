<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ThumbnailGenerator
{
    /**
     * Create an ImageManager instance with the configured driver
     */
    public static function createImageManager(): ImageManager
    {
        $driver = config('thumbnails.intervention_driver', 'gd');

        // Support for Intervention Image 3.x
        if ($driver === 'imagick') {
            return new ImageManager(
                new \Intervention\Image\Drivers\Imagick\Driver()
            );
        }

        return new ImageManager(
            new \Intervention\Image\Drivers\Gd\Driver()
        );
    }

    /**
     * Apply basic center crop to an image
     */
    public static function applyBasicCrop(ImageInterface $image, int $width, int $height): ImageInterface
    {
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        if ($originalWidth === $width && $originalHeight === $height) {
            return $image;
        }

        $originalRatio = $originalWidth / $originalHeight;
        $targetRatio = $width / $height;

        if ($originalRatio > $targetRatio) {
            // Crop horizontally
            $newWidth = $originalHeight * $targetRatio;
            $x = (int) round(($originalWidth - $newWidth) / 2);
            $image->crop($newWidth, $originalHeight, $x, 0);
        } elseif ($originalRatio < $targetRatio) {
            // Crop vertically
            $newHeight = $originalWidth / $targetRatio;
            $y = (int) round(($originalHeight - $newHeight) / 2);
            $image->crop($originalWidth, $newHeight, 0, $y);
        }

        return $image->resize($width, $height);
    }

    /**
     * Convert image to specified format with optimizations
     */
    public static function convertFormat(ImageInterface $image, string $format, int $quality = 85): string
    {
        $format = strtolower($format);

        switch ($format) {
            case 'avif':
                // AVIF format - requires ImageMagick driver
                // Quality: 0-100 (higher is better, 85 is a good balance)
                return $image->toAvif($quality)->toString();

            case 'webp':
                $lossless = config('thumbnails.webp_lossless', false);
                return $image->toWebp($lossless ? 100 : $quality)->toString();

            case 'png':
                // PNG quality is 0-9 in Intervention Image 3.x (compression level)
                $compression = config('thumbnails.png_compression', 6);
                return $image->toPng()->toString();

            case 'jpg':
            case 'jpeg':
            default:
                if (config('thumbnails.enable_progressive_jpeg', true)) {
                    return $image->toJpeg($quality)->toString();
                }
                return $image->toJpeg($quality)->toString();
        }
    }

    /**
     * Ensure destination directory exists
     */
    public static function ensureDestinationDirectory(string $disk, string $directory): void
    {
        try {
            $directory = self::normalizePath($directory);

            if (!empty($directory) && !Storage::disk($disk)->exists($directory)) {
                $parts = explode('/', $directory);
                $currentPath = '';

                foreach ($parts as $part) {
                    if (!empty($part)) {
                        $currentPath .= ($currentPath ? '/' : '') . $part;

                        if (!Storage::disk($disk)->exists($currentPath)) {
                            Storage::disk($disk)->makeDirectory($currentPath, 0755, true);

                            if (config('thumbnails.log_directory_creation', false)) {
                                Log::info("Created directory: {$currentPath} on disk: {$disk}");
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Could not create directory: {$directory} on disk: {$disk}", [
                    'error' => $e->getMessage(),
                    'directory' => $directory,
                    'disk' => $disk,
                    'normalized_directory' => self::normalizePath($directory),
                ]);
            }

            throw new \Exception("Cannot create directory structure: " . $e->getMessage());
        }
    }

    /**
     * Normalize filesystem path
     */
    public static function normalizePath(string $path): string
    {
        $path = preg_replace('/\/+/', '/', $path);
        $path = rtrim($path, '/');
        $path = ltrim($path, '/');
        return $path;
    }

    /**
     * Check if disk supports visibility setting
     */
    public static function diskSupportsVisibility(string $diskName): bool
    {
        $diskConfig = config("filesystems.disks.{$diskName}");

        if (!$diskConfig) {
            return false;
        }

        if ($diskConfig['driver'] === 'scoped') {
            $parentDisk = $diskConfig['disk'] ?? null;
            if ($parentDisk) {
                $parentConfig = config("filesystems.disks.{$parentDisk}");
                return $parentConfig && in_array($parentConfig['driver'], ['s3', 'gcs']);
            }
        }

        return in_array($diskConfig['driver'], ['s3', 'gcs', 'local']);
    }

    /**
     * Set file visibility to public if supported
     */
    public static function setPublicVisibility(string $disk, string $path): void
    {
        if (self::diskSupportsVisibility($disk)) {
            try {
                Storage::disk($disk)->setVisibility($path, 'public');
            } catch (\Exception $e) {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning('Could not set thumbnail visibility to public', [
                        'path' => $path,
                        'disk' => $disk,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Format bytes to human readable string
     */
    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Sanitize filename for safe storage
     */
    public static function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '', $filename);
        return substr($filename, 0, 100);
    }

    /**
     * Validate image content and extension
     */
    public static function validateImageContent(string $path): void
    {
        if (config('thumbnails.validate_image_content', true)) {
            $pathInfo = pathinfo($path);
            $extension = strtolower($pathInfo['extension'] ?? '');

            if (!empty($extension)) {
                $allowedExtensions = config('thumbnails.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                if (!in_array($extension, $allowedExtensions)) {
                    throw new \Exception("File extension '{$extension}' not allowed");
                }
            }
        }
    }

    /**
     * Generate subdirectory based on strategy
     */
    public static function generateSubdirectory(string $filename, array $config): string
    {
        $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');

        try {
            switch ($strategy) {
                case 'hash_prefix':
                    return self::generateHashPrefixSubdirectory($filename);
                case 'date_based':
                    return self::generateDateBasedSubdirectory();
                case 'filename_prefix':
                    return self::generateFilenamePrefixSubdirectory($filename);
                case 'hash_levels':
                    return self::generateHashLevelsSubdirectory($filename);
                case 'none':
                default:
                    return '';
            }
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Subdirectory strategy '{$strategy}' failed", [
                    'error' => $e->getMessage(),
                    'filename' => $filename,
                    'strategy' => $strategy,
                ]);
            }

            if ($strategy !== 'hash_prefix') {
                try {
                    Log::info("Falling back to hash_prefix for strategy: {$strategy}");
                    return self::generateHashPrefixSubdirectory($filename);
                } catch (\Exception $fallbackError) {
                    Log::error("Even hash_prefix subdirectory failed", [
                        'error' => $fallbackError->getMessage()
                    ]);
                    return '';
                }
            }

            return '';
        }
    }

    /**
     * Generate hash-based subdirectory (a/b/)
     */
    protected static function generateHashPrefixSubdirectory(string $filename): string
    {
        $hash = md5($filename);
        $level1 = substr($hash, 0, 1);
        $level2 = substr($hash, 1, 1);
        return $level1 . '/' . $level2 . '/';
    }

    /**
     * Generate filename-based subdirectory (ab/cd/)
     */
    protected static function generateFilenamePrefixSubdirectory(string $filename): string
    {
        $clean = preg_replace('/[^a-z0-9]/i', '', strtolower($filename));

        if (strlen($clean) < 2) {
            return 'misc/';
        }

        $level1 = substr($clean, 0, 1);
        $level2 = strlen($clean) > 1 ? substr($clean, 1, 1) : '0';

        return $level1 . '/' . $level2 . '/';
    }

    /**
     * Generate multi-level hash subdirectory (a/b/c/)
     */
    protected static function generateHashLevelsSubdirectory(string $filename): string
    {
        $hash = md5($filename);
        $level1 = substr($hash, 0, 1);
        $level2 = substr($hash, 1, 1);
        $level3 = substr($hash, 2, 1);
        return $level1 . '/' . $level2 . '/' . $level3 . '/';
    }

    /**
     * Generate date-based subdirectory (2025/01/28/)
     */
    protected static function generateDateBasedSubdirectory(): string
    {
        try {
            $dateDir = now()->format('Y/m/d') . '/';

            if (config('thumbnails.log_directory_creation', false)) {
                Log::info("Generated date-based subdirectory: {$dateDir}");
            }

            return $dateDir;
        } catch (\Exception $e) {
            Log::warning("Date-based subdirectory generation failed", [
                'error' => $e->getMessage(),
                'php_version' => PHP_VERSION,
                'now_available' => function_exists('now'),
                'date_available' => function_exists('date')
            ]);

            try {
                return date('Y/m/d') . '/';
            } catch (\Exception $dateError) {
                return date('Y') . '/' . date('m') . '/' . date('d') . '/';
            }
        }
    }
}
