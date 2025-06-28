<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManagerStatic as Image;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;

class ThumbnailService
{
    protected $config;
    protected $configKey;
    protected $sourcePath;
    protected $sourceDisk;
    protected $smartCropService;

    public function __construct(SmartCropService $smartCropService)
    {
        $this->config = config('thumbnails.presets', []);
        $this->smartCropService = $smartCropService;
    }

    public function set(string $configKey): self
    {
        $this->configKey = $configKey;

        if (!isset($this->config[$configKey])) {
            throw new \Exception("Thumbnail configuration '{$configKey}' not found");
        }

        return $this;
    }

    public function src(string $imagePath, string $sourceDisk = 'public'): self
    {
        $this->sourcePath = $imagePath;
        $this->sourceDisk = $sourceDisk;

        return $this;
    }

    public function url(string $variant = null): string
    {
        if (!$this->configKey || !$this->sourcePath) {
            throw new \Exception("Configuration key and source path must be set");
        }

        $this->validateSourceDisk();
        $config = $this->getEffectiveConfig($variant);
        $thumbnailPath = $this->generateThumbnailPath($config, $variant);

        if ($this->thumbnailExists($config['destination']['disk'], $thumbnailPath)) {
            return $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
        }

        $this->generateThumbnail($config, $thumbnailPath);
        return $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
    }

    protected function validateSourceDisk(): void
    {
        $availableDisks = array_keys(config('filesystems.disks'));

        if (!in_array($this->sourceDisk, $availableDisks)) {
            throw new \Exception("Source disk '{$this->sourceDisk}' is not configured");
        }

        try {
            Storage::disk($this->sourceDisk)->exists('');
        } catch (\Exception $e) {
            throw new \Exception("Source disk '{$this->sourceDisk}' is not accessible: " . $e->getMessage());
        }
    }

    protected function getEffectiveConfig(string $variant = null): array
    {
        $config = $this->config[$this->configKey];

        if ($variant && isset($config['variants'][$variant])) {
            $config = array_merge($config, $config['variants'][$variant]);
        }

        return $config;
    }

    protected function thumbnailExists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Exception $e) {
            Log::warning("Error checking thumbnail existence: " . $e->getMessage());
            return false;
        }
    }

    protected function getThumbnailUrl(string $disk, string $path): string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (\Exception $e) {
            throw new \Exception("Cannot generate URL for disk '{$disk}': " . $e->getMessage());
        }
    }

    protected function generateThumbnailPath(array $config, string $variant = null): string
    {
        $pathInfo = pathinfo($this->sourcePath);
        $filename = $pathInfo['filename'];
        $format = $config['format'] ?? config('thumbnails.default_format', 'jpg');

        $suffix = str_replace('x', '_', $config['smartcrop']);

        if ($variant) {
            $suffix .= '_' . $variant;
        }

        return $config['destination']['path'] .
            $filename . '_' .
            $suffix .
            '.' . $format;
    }

    protected function generateThumbnail(array $config, string $thumbnailPath): void
    {
        try {
            if (!Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
                throw new \Exception("Source image not found: {$this->sourcePath}");
            }

            $imageContent = Storage::disk($this->sourceDisk)->get($this->sourcePath);

            if (empty($imageContent)) {
                throw new \Exception("Source image is empty: {$this->sourcePath}");
            }

            $image = Image::make($imageContent);

            [$width, $height] = explode('x', $config['smartcrop']);
            $width = (int) $width;
            $height = (int) $height;

            if ($width <= 0 || $height <= 0) {
                throw new \Exception("Invalid dimensions: {$config['smartcrop']}");
            }

            // Applica smart crop se abilitato
            $smartCropEnabled = $config['smart_crop_enabled'] ?? config('thumbnails.enable_smart_crop', true);

            if ($smartCropEnabled) {
                $image = $this->smartCropService->smartCrop($image, $width, $height);
            } else {
                $image = $this->applyBasicCrop($image, $width, $height);
            }

            $format = $config['format'] ?? config('thumbnails.default_format', 'jpg');
            $quality = $config['quality'] ?? config('thumbnails.default_quality', 85);
            $processedImage = $this->convertFormat($image, $format, $quality);

            $this->ensureDestinationDirectory($config['destination']['disk'], dirname($thumbnailPath));

            Storage::disk($config['destination']['disk'])
                ->put($thumbnailPath, $processedImage->getContents());

            Log::info("Thumbnail generated", [
                'source' => $this->sourcePath,
                'destination' => $thumbnailPath,
                'smart_crop' => $smartCropEnabled
            ]);
        } catch (\Exception $e) {
            Log::error('Thumbnail generation failed: ' . $e->getMessage(), [
                'source' => $this->sourcePath,
                'destination' => $thumbnailPath,
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    protected function applyBasicCrop($image, int $width, int $height)
    {
        $originalWidth = $image->width();
        $originalHeight = $image->height();
        $originalRatio = $originalWidth / $originalHeight;
        $targetRatio = $width / $height;

        if ($originalRatio > $targetRatio) {
            $newWidth = $originalHeight * $targetRatio;
            $x = ($originalWidth - $newWidth) / 2;
            $image->crop($newWidth, $originalHeight, $x, 0);
        } elseif ($originalRatio < $targetRatio) {
            $newHeight = $originalWidth / $targetRatio;
            $y = ($originalHeight - $newHeight) / 2;
            $image->crop($originalWidth, $newHeight, 0, $y);
        }

        return $image->resize($width, $height);
    }

    protected function ensureDestinationDirectory(string $disk, string $directory): void
    {
        try {
            if (!Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->makeDirectory($directory);
            }
        } catch (\Exception $e) {
            Log::warning("Could not create directory: " . $e->getMessage());
        }
    }

    protected function convertFormat($image, string $format, int $quality = 85)
    {
        switch (strtolower($format)) {
            case 'webp':
                return $image->encode('webp', $quality);
            case 'png':
                return $image->encode('png');
            case 'jpg':
            case 'jpeg':
            default:
                return $image->encode('jpg', $quality);
        }
    }

    public function getAvailableDisks(): array
    {
        return array_keys(config('filesystems.disks'));
    }

    public function getScopedDisks(): array
    {
        $disks = config('filesystems.disks');
        $scopedDisks = [];

        foreach ($disks as $name => $config) {
            if (isset($config['driver']) && $config['driver'] === 'scoped') {
                $scopedDisks[$name] = [
                    'parent_disk' => $config['disk'] ?? null,
                    'prefix' => $config['prefix'] ?? null,
                ];
            }
        }

        return $scopedDisks;
    }

    public function testDisk(string $disk): array
    {
        try {
            Storage::disk($disk)->exists('');
            return ['accessible' => true, 'error' => null];
        } catch (\Exception $e) {
            return ['accessible' => false, 'error' => $e->getMessage()];
        }
    }

    public function getVariants(string $configKey = null): array
    {
        $configKey = $configKey ?? $this->configKey;

        if (!$configKey || !isset($this->config[$configKey])) {
            return [];
        }

        return $this->config[$configKey]['variants'] ?? [];
    }

    public function purgeAll(): int
    {
        $purgedCount = 0;

        foreach ($this->config as $preset) {
            $disk = $preset['destination']['disk'];
            $path = $preset['destination']['path'];

            try {
                $files = Storage::disk($disk)->allFiles($path);

                foreach ($files as $file) {
                    if ($this->isThumbnailFile($file)) {
                        Storage::disk($disk)->delete($file);
                        $purgedCount++;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Could not purge thumbnails from {$disk}:{$path} - " . $e->getMessage());
            }
        }

        return $purgedCount;
    }

    public function purgePreset(string $preset): int
    {
        if (!isset($this->config[$preset])) {
            throw new \Exception("Preset '{$preset}' not found");
        }

        $config = $this->config[$preset];
        $disk = $config['destination']['disk'];
        $path = $config['destination']['path'];
        $purgedCount = 0;

        try {
            $files = Storage::disk($disk)->allFiles($path);

            foreach ($files as $file) {
                if ($this->isThumbnailFile($file)) {
                    Storage::disk($disk)->delete($file);
                    $purgedCount++;
                }
            }
        } catch (\Exception $e) {
            throw new \Exception("Could not purge preset '{$preset}': " . $e->getMessage());
        }

        return $purgedCount;
    }

    protected function isThumbnailFile(string $filePath): bool
    {
        $filename = basename($filePath);

        // I thumbnail hanno il pattern: filename_width_height[_variant].extension
        return preg_match('/.*_\d+_\d+(_\w+)?\.(jpg|jpeg|png|webp)$/i', $filename);
    }
}
