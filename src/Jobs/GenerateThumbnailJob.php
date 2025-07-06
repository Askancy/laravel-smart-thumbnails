<?php

namespace Askancy\LaravelSmartThumbnails\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManagerStatic as Image;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $sourcePath;
    protected $sourceDisk;
    protected $configKey;
    protected $config;
    protected $thumbnailPath;
    protected $variant;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function __construct(
        string $sourcePath,
        string $sourceDisk,
        string $configKey,
        array $config,
        string $thumbnailPath,
        string $variant = null
    ) {
        $this->sourcePath = $sourcePath;
        $this->sourceDisk = $sourceDisk;
        $this->configKey = $configKey;
        $this->config = $config;
        $this->thumbnailPath = $thumbnailPath;
        $this->variant = $variant;
    }

    public function handle(SmartCropService $smartCropService)
    {
        try {
            // Verifica se il thumbnail è già stato generato nel frattempo
            if (Storage::disk($this->config['destination']['disk'])->exists($this->thumbnailPath)) {
                Log::info('Thumbnail already exists, skipping generation', [
                    'path' => $this->thumbnailPath
                ]);
                return;
            }

            // Configura Intervention Image
            $driver = config('thumbnails.intervention_driver', 'gd');
            Image::configure(['driver' => $driver]);

            $memoryLimit = config('thumbnails.memory_limit');
            if ($memoryLimit) {
                ini_set('memory_limit', $memoryLimit);
            }

            $startTime = microtime(true);

            // Verifica esistenza file sorgente
            if (!Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
                throw new \Exception("Source image not found: {$this->sourcePath}");
            }

            $imageContent = Storage::disk($this->sourceDisk)->get($this->sourcePath);

            if (empty($imageContent)) {
                throw new \Exception("Source image is empty: {$this->sourcePath}");
            }

            $image = Image::make($imageContent);
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            [$width, $height] = explode('x', $this->config['smartcrop']);
            $width = (int) $width;
            $height = (int) $height;

            if ($width <= 0 || $height <= 0) {
                throw new \Exception("Invalid dimensions: {$this->config['smartcrop']}");
            }

            // Applica smart crop se abilitato
            $smartCropEnabled = $this->config['smart_crop_enabled'] ?? config('thumbnails.enable_smart_crop', true);

            if ($smartCropEnabled) {
                // Ottimizza smart crop per immagini grandi
                $maxAnalysisSize = config('thumbnails.max_smart_crop_analysis_size', 800);
                if ($originalWidth > $maxAnalysisSize || $originalHeight > $maxAnalysisSize) {
                    $analysisImage = clone $image;
                    $analysisImage->resize($maxAnalysisSize, $maxAnalysisSize, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                    $image = $smartCropService->smartCrop($analysisImage, $width, $height);
                } else {
                    $image = $smartCropService->smartCrop($image, $width, $height);
                }
            } else {
                $image = $this->applyBasicCrop($image, $width, $height);
            }

            $format = $this->config['format'] ?? config('thumbnails.default_format', 'jpg');
            $quality = $this->config['quality'] ?? config('thumbnails.default_quality', 85);

            $processedImage = $this->convertFormat($image, $format, $quality);

            // Assicura che la directory di destinazione esista
            $this->ensureDestinationDirectory($this->config['destination']['disk'], dirname($this->thumbnailPath));

            // Salva il thumbnail
            $disk = Storage::disk($this->config['destination']['disk']);
            $disk->put($this->thumbnailPath, $processedImage);

            // Imposta visibilità pubblica se supportata
            if ($this->diskSupportsVisibility($this->config['destination']['disk'])) {
                try {
                    $disk->setVisibility($this->thumbnailPath, 'public');
                } catch (\Exception $e) {
                    Log::warning('Could not set thumbnail visibility to public', [
                        'path' => $this->thumbnailPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $executionTime = microtime(true) - $startTime;

            Log::info('Thumbnail generated asynchronously', [
                'source' => $this->sourcePath,
                'destination' => $this->thumbnailPath,
                'config' => $this->configKey,
                'variant' => $this->variant,
                'execution_time' => round($executionTime * 1000, 2) . 'ms'
            ]);

            // Cleanup memory
            $image->destroy();
        } catch (\Exception $e) {
            Log::error('Async thumbnail generation failed', [
                'source' => $this->sourcePath,
                'destination' => $this->thumbnailPath,
                'config' => $this->configKey,
                'variant' => $this->variant,
                'error' => $e->getMessage(),
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

    protected function convertFormat($image, string $format, int $quality = 85)
    {
        switch (strtolower($format)) {
            case 'webp':
                $lossless = config('thumbnails.webp_lossless', false);
                return $image->encode('webp', $lossless ? 100 : $quality)->encoded;

            case 'png':
                $compression = config('thumbnails.png_compression', 6);
                return $image->encode('png', $compression)->encoded;

            case 'jpg':
            case 'jpeg':
            default:
                return $image->encode('jpg', $quality)->encoded;
        }
    }

    protected function ensureDestinationDirectory(string $disk, string $directory): void
    {
        try {
            $directory = $this->normalizePath($directory);

            if (!Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->makeDirectory($directory, 0755, true);
            }
        } catch (\Exception $e) {
            Log::warning("Could not create directory: " . $e->getMessage());
        }
    }

    protected function normalizePath(string $path): string
    {
        $path = preg_replace('/\/+/', '/', $path);
        $path = rtrim($path, '/');
        $path = ltrim($path, '/');
        return $path;
    }

    protected function diskSupportsVisibility(string $diskName): bool
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

    public function failed(\Exception $exception)
    {
        Log::error('Thumbnail generation job failed permanently', [
            'source' => $this->sourcePath,
            'destination' => $this->thumbnailPath,
            'config' => $this->configKey,
            'variant' => $this->variant,
            'error' => $exception->getMessage()
        ]);
    }
}
