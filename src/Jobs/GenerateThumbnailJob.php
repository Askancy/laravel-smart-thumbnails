<?php

namespace Askancy\LaravelSmartThumbnails\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;

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

    /**
     * Execute the job
     * Uses Intervention Image 3.x API and ThumbnailGenerator utilities
     *
     * @param SmartCropService $smartCropService
     * @return void
     * @throws \Exception
     */
    public function handle(SmartCropService $smartCropService)
    {
        try {
            // Check if thumbnail already exists (race condition protection)
            if (Storage::disk($this->config['destination']['disk'])->exists($this->thumbnailPath)) {
                Log::info('Thumbnail already exists, skipping generation', [
                    'path' => $this->thumbnailPath
                ]);
                return;
            }

            // Initialize ImageManager with Intervention Image 3.x
            $imageManager = ThumbnailGenerator::createImageManager();

            // Configure memory limit
            $memoryLimit = config('thumbnails.memory_limit');
            if ($memoryLimit) {
                ini_set('memory_limit', $memoryLimit);
            }

            $startTime = microtime(true);

            // Validate source file existence
            if (!Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
                throw new \Exception("Source image not found: {$this->sourcePath}");
            }

            $imageContent = Storage::disk($this->sourceDisk)->get($this->sourcePath);

            if (empty($imageContent)) {
                throw new \Exception("Source image is empty: {$this->sourcePath}");
            }

            // Read image using Intervention Image 3.x
            $image = $imageManager->read($imageContent);
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            [$width, $height] = explode('x', $this->config['smartcrop']);
            $width = (int) $width;
            $height = (int) $height;

            if ($width <= 0 || $height <= 0) {
                throw new \Exception("Invalid dimensions: {$this->config['smartcrop']}");
            }

            // Apply smart crop if enabled
            $smartCropEnabled = $this->config['smart_crop_enabled'] ?? config('thumbnails.enable_smart_crop', true);

            if ($smartCropEnabled) {
                // Optimize smart crop for large images by analyzing at smaller size
                $maxAnalysisSize = config('thumbnails.max_smart_crop_analysis_size', 800);
                if ($originalWidth > $maxAnalysisSize || $originalHeight > $maxAnalysisSize) {
                    // For large images, use basic crop to reduce memory usage
                    $image = ThumbnailGenerator::applyBasicCrop($image, $width, $height);
                } else {
                    try {
                        $image = $smartCropService->smartCrop($image, $width, $height);
                    } catch (\Exception $e) {
                        // Fallback to basic crop if smart crop fails
                        Log::warning('Smart crop failed, using basic crop', [
                            'error' => $e->getMessage(),
                            'source' => $this->sourcePath
                        ]);
                        $image = ThumbnailGenerator::applyBasicCrop($image, $width, $height);
                    }
                }
            } else {
                $image = ThumbnailGenerator::applyBasicCrop($image, $width, $height);
            }

            $format = $this->config['format'] ?? config('thumbnails.default_format', 'jpg');
            $quality = $this->config['quality'] ?? config('thumbnails.default_quality', 85);

            $processedImage = ThumbnailGenerator::convertFormat($image, $format, $quality);

            // Ensure destination directory exists
            ThumbnailGenerator::ensureDestinationDirectory($this->config['destination']['disk'], dirname($this->thumbnailPath));

            // Save thumbnail
            $disk = Storage::disk($this->config['destination']['disk']);
            $disk->put($this->thumbnailPath, $processedImage);

            // Set public visibility if supported
            ThumbnailGenerator::setPublicVisibility($this->config['destination']['disk'], $this->thumbnailPath);

            $executionTime = microtime(true) - $startTime;

            Log::info('Thumbnail generated asynchronously', [
                'source' => $this->sourcePath,
                'destination' => $this->thumbnailPath,
                'config' => $this->configKey,
                'variant' => $this->variant,
                'execution_time' => round($executionTime * 1000, 2) . 'ms'
            ]);

            // Cleanup memory (Intervention Image 3.x manages this automatically)
            unset($image);
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

    /**
     * Handle job failure
     *
     * @param \Exception $exception
     * @return void
     */
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
