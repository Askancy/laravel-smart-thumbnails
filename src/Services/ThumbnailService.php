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
    protected $silentMode = false;

    public function __construct(SmartCropService $smartCropService)
    {
        $this->config = config('thumbnails.presets', []);
        $this->smartCropService = $smartCropService;
    }

    public function set(string $configKey): self
    {
        $this->configKey = $configKey;

        if (!isset($this->config[$configKey])) {
            if ($this->silentMode) {
                Log::warning("Thumbnail configuration '{$configKey}' not found");
                return $this;
            }
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

    /**
     * Abilita modalità silenziosa - non lancia eccezioni ma ritorna placeholder
     */
    public function silent(): self
    {
        $this->silentMode = true;
        return $this;
    }

    /**
     * Disabilita modalità silenziosa - comportamento normale con eccezioni
     */
    public function strict(): self
    {
        $this->silentMode = false;
        return $this;
    }

    public function url(string $variant = null): string
    {
        try {
            return $this->generateUrl($variant);
        } catch (\Exception $e) {
            if ($this->silentMode) {
                Log::warning('Thumbnail generation failed (silent mode)', [
                    'error' => $e->getMessage(),
                    'source' => $this->sourcePath,
                    'disk' => $this->sourceDisk,
                    'config' => $this->configKey,
                    'variant' => $variant
                ]);

                return $this->getFallbackUrl($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Genera URL con fallback automatico in caso di errore
     */
    public function urlSafe(string $variant = null): string
    {
        $memoryBefore = memory_get_usage(true);

        try {
            $originalMode = $this->silentMode ?? false;
            $this->silentMode = true;

            $result = $this->url($variant);

            $memoryAfter = memory_get_usage(true);
            $memoryUsed = $memoryAfter - $memoryBefore;

            Log::info('urlSafe completed', [
                'memory_used' => $memoryUsed,
                'memory_total' => $memoryAfter,
                'url' => $result
            ]);

            $this->silentMode = $originalMode;
            return $result;
        } catch (\Exception $e) {
            Log::error('urlSafe failed', [
                'error' => $e->getMessage(),
                'memory_usage' => memory_get_usage(true)
            ]);

            throw $e;
        }
    }

    protected function generateUrl(string $variant = null): string
    {
        if (!$this->configKey || !$this->sourcePath) {
            throw new \Exception("Configuration key and source path must be set");
        }

        // Validazione morbida in modalità silenziosa
        if (!$this->validateSourceDiskSafe()) {
            throw new \Exception("Source disk validation failed");
        }

        $config = $this->getEffectiveConfig($variant);
        $thumbnailPath = $this->generateThumbnailPath($config, $variant);

        if ($this->thumbnailExists($config['destination']['disk'], $thumbnailPath)) {
            return $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
        }

        $this->generateThumbnail($config, $thumbnailPath);
        return $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
    }

    protected function validateSourceDiskSafe(): bool
    {
        try {
            $availableDisks = array_keys(config('filesystems.disks'));

            if (!in_array($this->sourceDisk, $availableDisks)) {
                throw new \Exception("Source disk '{$this->sourceDisk}' is not configured");
            }

            Storage::disk($this->sourceDisk)->exists('');
            return true;
        } catch (\Exception $e) {
            if ($this->silentMode) {
                Log::warning("Source disk validation failed: " . $e->getMessage());
                return false;
            }
            throw $e;
        }
    }

    protected function getEffectiveConfig(string $variant = null): array
    {
        $config = $this->config[$this->configKey] ?? [];

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

        $suffix = str_replace('x', '_', $config['smartcrop'] ?? '100x100');

        if ($variant) {
            $suffix .= '_' . $variant;
        }

        return ($config['destination']['path'] ?? 'crops/') .
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

            // Fix: Metodo più compatibile per GD
            $processedImage = $this->convertFormatGDCompatible($image, $format, $quality);

            $this->ensureDestinationDirectory($config['destination']['disk'], dirname($thumbnailPath));

            Storage::disk($config['destination']['disk'])
                ->put($thumbnailPath, $processedImage);

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

    protected function convertFormatGDCompatible($image, string $format, int $quality = 85)
    {
        switch (strtolower($format)) {
            case 'webp':
                return $image->encode('webp', $quality)->encoded;
            case 'png':
                return $image->encode('png')->encoded;
            case 'jpg':
            case 'jpeg':
            default:
                return $image->encode('jpg', $quality)->encoded;
        }
    }

    /**
     * Genera un'immagine placeholder in caso di errore
     */
    protected function generatePlaceholder(array $config, string $thumbnailPath): void
    {
        try {
            [$width, $height] = explode('x', $config['smartcrop'] ?? '100x100');
            $width = max(1, (int) $width);
            $height = max(1, (int) $height);

            // Crea un'immagine placeholder grigia con testo di errore
            $placeholder = Image::canvas($width, $height, '#f0f0f0');

            // Aggiungi un testo di errore se possibile
            if (function_exists('imagettftext')) {
                $placeholder->text('Image\nError', $width / 2, $height / 2, function ($font) {
                    $font->file(storage_path('fonts/arial.ttf')); // Se hai un font
                    $font->size(12);
                    $font->color('#999999');
                    $font->align('center');
                    $font->valign('middle');
                });
            }

            $format = $config['format'] ?? 'jpg';
            $quality = $config['quality'] ?? 75;
            $processedImage = $this->convertFormat($placeholder, $format, $quality);

            Storage::disk($config['destination']['disk'] ?? 'public')
                ->put($thumbnailPath, $processedImage->getContents());

            Log::info("Placeholder generated for failed thumbnail", [
                'source' => $this->sourcePath,
                'placeholder_path' => $thumbnailPath
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate placeholder: ' . $e->getMessage());
        }
    }

    /**
     * Ritorna URL di fallback in caso di errore
     */
    protected function getFallbackUrl(string $errorMessage = ''): string
    {
        // Prova diversi fallback in ordine di preferenza

        // 1. Prova l'immagine originale se accessibile
        try {
            if ($this->sourcePath && $this->sourceDisk) {
                if (Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
                    return Storage::disk($this->sourceDisk)->url($this->sourcePath);
                }
            }
        } catch (\Exception $e) {
            // Ignora e continua con il prossimo fallback
        }

        // 2. Prova placeholder configurato
        $placeholderUrl = config('thumbnails.placeholder_url');
        if ($placeholderUrl) {
            return $placeholderUrl;
        }

        // 3. URL placeholder generato via data URI
        return $this->generateDataUriPlaceholder($errorMessage);
    }

    /**
     * Genera un'immagine placeholder come data URI
     */
    protected function generateDataUriPlaceholder(string $errorMessage = ''): string
    {
        try {
            $config = $this->getEffectiveConfig();
            [$width, $height] = explode('x', $config['smartcrop'] ?? '100x100');
            $width = max(50, min(500, (int) $width));
            $height = max(50, min(500, (int) $height));

            $placeholder = Image::canvas($width, $height, '#f8f9fa');

            // Aggiungi bordo e testo
            $placeholder->rectangle(0, 0, $width - 1, $height - 1, function ($draw) {
                $draw->border(1, '#dee2e6');
            });

            $placeholder->text('❌', $width / 2, $height / 2 - 10, function ($font) {
                $font->size(min(24, $width / 4));
                $font->color('#6c757d');
                $font->align('center');
                $font->valign('middle');
            });

            if ($height > 60) {
                $placeholder->text('Error', $width / 2, $height / 2 + 15, function ($font) {
                    $font->size(min(12, $width / 8));
                    $font->color('#6c757d');
                    $font->align('center');
                    $font->valign('middle');
                });
            }

            $imageData = $placeholder->encode('png')->getEncoded();
            return 'data:image/png;base64,' . base64_encode($imageData);
        } catch (\Exception $e) {
            // Fallback finale: SVG placeholder
            $svg = '<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg">
                <rect width="100%" height="100%" fill="#f8f9fa" stroke="#dee2e6"/>
                <text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#6c757d">❌</text>
            </svg>';

            return 'data:image/svg+xml;base64,' . base64_encode($svg);
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
                $encoded = $image->encode('webp', $quality);
                break;
            case 'png':
                $encoded = $image->encode('png');
                break;
            case 'jpg':
            case 'jpeg':
            default:
                $encoded = $image->encode('jpg', $quality);
                break;
        }

        // Fix: Usa response() invece di getContents()
        return $encoded->response();
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
            $disk = $preset['destination']['disk'] ?? 'public';
            $path = $preset['destination']['path'] ?? 'crops/';

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
        $disk = $config['destination']['disk'] ?? 'public';
        $path = $config['destination']['path'] ?? 'crops/';
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
