<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Askancy\LaravelSmartThumbnails\Services\DontCropService;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

class ThumbnailGenerator
{
    public static function diskUrl(string $disk, string $path): string
    {
        $storage = Storage::disk($disk);
        if (!method_exists($storage, 'url')) {
            throw new \RuntimeException("Disk '{$disk}' cannot produce image URLs.");
        }
        return $storage->url($path);
    }

    /** Shared rendering path for synchronous requests and queue workers. */
    public static function transform(
        ImageInterface $image,
        int $width,
        int $height,
        array $config,
        SmartCropService $smartCrop
    ): ImageInterface {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Thumbnail dimensions must be positive.');
        }
        $mode = $config['crop_mode'] ?? config('thumbnails.crop_mode');
        $mode ??= ($config['smart_crop_enabled'] ?? config('thumbnails.enable_smart_crop', true)) ? 'smart' : 'center';

        $allowUpscale = $config['allow_upscale'] ?? config('thumbnails.allow_upscale', true);
        if (!$allowUpscale && $mode !== 'dont_crop') {
            $factor = min(1, $image->width() / $width, $image->height() / $height);
            $width = max(1, (int) floor($width * $factor));
            $height = max(1, (int) floor($height * $factor));
        }

        if ($mode === 'dont_crop') {
            return (new DontCropService())->contain(
                $image, $width, $height,
                $config['dont_crop_background'] ?? config('thumbnails.dont_crop_background', 'gradient'),
                $allowUpscale
            );
        }
        if ($mode === 'center') {
            return self::applyBasicCrop($image, $width, $height);
        }
        if ($mode !== 'smart') {
            throw new \InvalidArgumentException("Unknown crop mode '{$mode}'. Use smart, center, or dont_crop.");
        }
        try {
            return $smartCrop->smartCrop($image, $width, $height);
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning('Smart crop analysis failed, using center crop', ['error' => $e->getMessage()]);
            }
            return self::applyBasicCrop($image, $width, $height);
        }
    }

    /** Separate explicitly selected modes/backgrounds while retaining legacy filenames. */
    public static function renderingSuffix(array $config): string
    {
        $mode = $config['crop_mode'] ?? config('thumbnails.crop_mode');
        if ($mode === null) {
            return '';
        }
        if (!in_array($mode, ['smart', 'center', 'dont_crop'], true)) {
            throw new \InvalidArgumentException("Unknown crop mode '{$mode}'.");
        }
        $background = $config['dont_crop_background'] ?? config('thumbnails.dont_crop_background', 'gradient');

        return '_' . $mode . '_' . substr(sha1(serialize([$mode, $background])), 0, 8);
    }

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
    public static function convertFormat(ImageInterface $image, string $format, int $quality = 85, array $options = []): string
    {
        $format = strtolower($format);

        switch ($format) {
            case 'avif':
                if (!$image->driver()->supports('avif')) {
                    throw new \RuntimeException('AVIF encoding is not supported by the configured image driver. Enable AVIF support in GD or Imagick.');
                }

                // Quality: 0-100 (higher is better, 85 is a good balance)
                return $image->toAvif($quality)->toString();

            case 'webp':
                $lossless = $options['webp_lossless'] ?? config('thumbnails.webp_lossless', false);
                if ($lossless && !$image->driver()->supports('webp')) {
                    throw new \RuntimeException('The configured image driver does not support WebP encoding.');
                }
                if ($lossless && $image->core()->native() instanceof \GdImage) {
                    ob_start();
                    try {
                        if (!imagewebp($image->core()->native(), null, IMG_WEBP_LOSSLESS)) {
                            throw new \RuntimeException('Lossless WebP encoding failed.');
                        }
                        return (string) ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                }
                if ($lossless && $image->core()->native() instanceof \Imagick) {
                    $image = clone $image;
                    $image->core()->native()->setOption('webp:lossless', 'true');
                }
                return $image->toWebp($quality)->toString();

            case 'png':
                $compression = $options['png_compression'] ?? config('thumbnails.png_compression', 6);
                if ($compression < 0 || $compression > 9) {
                    throw new \InvalidArgumentException('PNG compression must be between 0 and 9.');
                }
                if ($image->core()->native() instanceof \GdImage) {
                    ob_start();
                    try {
                        if (!imagepng($image->core()->native(), null, $compression)) {
                            throw new \RuntimeException('PNG encoding failed.');
                        }
                        return (string) ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                }
                if ($image->core()->native() instanceof \Imagick) {
                    $image = clone $image;
                    $image->core()->native()->setOption('png:compression-level', (string) $compression);
                }
                return $image->toPng()->toString();

            case 'jpg':
            case 'jpeg':
                return $image->toJpeg($quality, progressive: $options['enable_progressive_jpeg'] ?? config('thumbnails.enable_progressive_jpeg', true))->toString();
            default:
                throw new \InvalidArgumentException("Unsupported thumbnail format '{$format}'.");
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
                            Storage::disk($disk)->makeDirectory($currentPath);

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
    public static function validateImageContent(string $path, ?string $binary = null): void
    {
        if (config('thumbnails.validate_image_content', true)) {
            $pathInfo = pathinfo($path);
            $extension = strtolower($pathInfo['extension'] ?? '');

            if (!empty($extension)) {
                $allowedExtensions = config('thumbnails.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']);
                if (!in_array($extension, $allowedExtensions)) {
                    throw new \Exception("File extension '{$extension}' not allowed");
                }
            }
            if ($binary !== null) {
                $info = @getimagesizefromstring($binary);
                $mime = $info['mime'] ?? null;
                $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                    'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif'];
                if ($info === false || !in_array($mime, $types, true)) {
                    throw new \RuntimeException("Source is not a supported image: {$path}");
                }
                if ($extension !== '' && ($types[$extension] ?? null) !== $mime) {
                    throw new \RuntimeException("Image content does not match file extension: {$path}");
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
                        'error' => $fallbackError->getMessage(),
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
        $level2 = substr($clean, 1, 1);

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
                'date_available' => function_exists('date'),
            ]);

            try {
                return date('Y/m/d') . '/';
            } catch (\Exception $dateError) {
                return date('Y') . '/' . date('m') . '/' . date('d') . '/';
            }
        }
    }
}
