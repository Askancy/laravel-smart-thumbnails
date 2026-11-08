<?php

namespace Askancy\LaravelSmartThumbnails\Jobs;

use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Support\CacheHelper;
use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\PixelBudget;
use Askancy\LaravelSmartThumbnails\Support\SourceImage;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailIdentity;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected $sourcePath;
    protected $sourceDisk;
    protected $configKey;
    protected $config;
    protected $thumbnailPath;
    protected $variant;
    protected bool $force = false;
    protected bool $managedPath = false;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [10, 30, 60];

    public function __construct(
        string $sourcePath,
        string $sourceDisk,
        string $configKey,
        array $config,
        string $thumbnailPath,
        ?string $variant = null,
        bool $force = false,
        bool $managedPath = false
    ) {
        $this->sourcePath = $sourcePath;
        $this->sourceDisk = $sourceDisk;
        $this->configKey = $configKey;
        $this->config = ThumbnailIdentity::settings($config);
        $this->thumbnailPath = $thumbnailPath;
        $this->variant = $variant;
        $this->force = $force;
        $this->managedPath = $managedPath;
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
            $source = SourceImage::read($this->sourcePath, $this->sourceDisk, $this->config);
            if ($this->managedPath) {
                $this->thumbnailPath = ThumbnailIdentity::path(
                    $this->sourcePath,
                    $this->sourceDisk,
                    $this->config,
                    $this->variant,
                    $source['hash']
                );
            }
            GenerationLock::run($this->config['destination']['disk'], $this->thumbnailPath, function (Lock $lock) use ($source, $smartCropService) {
                if (!$this->force && config('thumbnails.cache_thumbnails', true)
                    && Storage::disk($this->config['destination']['disk'])->exists($this->thumbnailPath)) {
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

                $imageContent = $source['content'];

                if (empty($imageContent)) {
                    throw new \Exception("Source image is empty: {$this->sourcePath}");
                }

                // Same guard as ThumbnailService: the decoder allocates
                // width x height x 4 bytes and a few MB of PNG can exhaust the
                // worker's memory. A worker killed this way leaves nothing in
                // failed_jobs, so it is cheaper to refuse the image and log why.
                $budget = PixelBudget::configuredBudget();

                if (PixelBudget::exceeds($imageContent, $budget)) {
                    $megapixels = round((float) PixelBudget::megapixels($imageContent), 1);

                    Log::warning('Source image refused: too many pixels for a thumbnail', [
                        'source' => $this->sourcePath,
                        'disk' => $this->sourceDisk,
                        'megapixels' => $megapixels,
                        'budget_megapixels' => $budget,
                    ]);

                    throw new \Exception(sprintf(
                        'Source image too large to decode: %s megapixels, budget is %s (%s)',
                        $megapixels,
                        $budget,
                        $this->sourcePath
                    ));
                }

                // Read image using Intervention Image 3.x
                $image = $imageManager->read($imageContent);

                [$width, $height] = explode('x', $this->config['smartcrop']);
                $width = (int) $width;
                $height = (int) $height;

                if ($width <= 0 || $height <= 0) {
                    throw new \Exception("Invalid dimensions: {$this->config['smartcrop']}");
                }

                $image = ThumbnailGenerator::transform($image, $width, $height, $this->config, $smartCropService);

                $format = $this->config['format'] ?? config('thumbnails.default_format', 'jpg');
                $quality = $this->config['quality'] ?? config('thumbnails.default_quality', 85);

                $processedImage = ThumbnailGenerator::convertFormat($image, $format, $quality, $this->config);

                $record = $this->managedPath ? ThumbnailManifest::record(
                    $this->sourcePath,
                    $this->sourceDisk,
                    $this->configKey,
                    $this->variant,
                    $this->config,
                    $source['hash']
                ) : null;
                ThumbnailPublisher::publish($this->config['destination']['disk'], $this->thumbnailPath, $processedImage, $lock, $record);
                CacheHelper::flush([$this->configKey]);
                $executionTime = microtime(true) - $startTime;

                Log::info('Thumbnail generated asynchronously', [
                    'source' => $this->sourcePath,
                    'destination' => $this->thumbnailPath,
                    'config' => $this->configKey,
                    'variant' => $this->variant,
                    'execution_time' => round($executionTime * 1000, 2) . 'ms',
                ]);

                // Cleanup memory (Intervention Image 3.x manages this automatically)
                unset($image);
            });
        } catch (\Exception $e) {
            Log::error('Async thumbnail generation failed', [
                'source' => $this->sourcePath,
                'destination' => $this->thumbnailPath,
                'config' => $this->configKey,
                'variant' => $this->variant,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
            'error' => $exception->getMessage(),
        ]);
    }
}
