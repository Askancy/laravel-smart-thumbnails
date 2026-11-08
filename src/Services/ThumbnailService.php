<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Askancy\LaravelSmartThumbnails\Support\CacheHelper;
use Askancy\LaravelSmartThumbnails\Support\PixelBudget;
use Askancy\LaravelSmartThumbnails\Support\SourceImage;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailIdentity;
use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailPublisher;
use Illuminate\Contracts\Cache\Lock;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ThumbnailService
{
    // === CONFIGURAZIONE CACHE ===
    protected const CACHE_PREFIX = 'thumb_url:';
    protected const CACHE_TTL = 3600; // secondi (1 ora) - Consistente
    protected const CACHE_TAG = 'thumbnails'; // Tag per invalidazione mirata

    protected $config;
    protected $configKey;
    protected $sourcePath;
    protected $sourceDisk;
    protected $smartCropService;
    protected $silentMode;
    protected $imageManager;
    protected ?array $sourceSnapshot = null;

    // ==================== CACHE OTTIMIZZATA - SOLO DISK CACHE STATICA ====================
    protected static array $diskCache = [];

    public function __construct(SmartCropService $smartCropService)
    {
        $this->config = config('thumbnails.presets', []);
        $this->smartCropService = $smartCropService;
        $this->silentMode = config('thumbnails.silent_mode_default', true);
        $this->imageManager = ThumbnailGenerator::createImageManager();
        $this->configureMemoryLimit();
    }

    protected function configureMemoryLimit(): void
    {
        $memoryLimit = config('thumbnails.memory_limit');
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }
    }

    public function set(string $configKey): self
    {
        $this->config = config('thumbnails.presets', []);
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
        $this->sourceSnapshot = null;
        return $this;
    }

    public function silent(): self
    {
        $this->silentMode = true;
        return $this;
    }

    public function strict(): self
    {
        $this->silentMode = false;
        return $this;
    }

    public function urlSafe(?string $variant = null): string
    {
        $originalMode = $this->silentMode;
        $this->silentMode = true;

        try {
            $result = $this->url($variant);
            $this->silentMode = $originalMode;
            return $result;
        } catch (\Throwable $e) {
            $this->silentMode = $originalMode;

            try {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning('ThumbnailService::urlSafe() fallback', [
                        'error' => $e->getMessage(),
                        'config_key' => $this->configKey ?? 'unknown',
                        'source_path' => $this->sourcePath ?? 'unknown',
                        'variant' => $variant,
                        'request_url' => $this->getCurrentUrl(),
                    ]);
                }
            } catch (\Throwable $logError) {
                // Ignora errori di log
            }

            return $this->getFallbackUrlSafe();
        }
    }

    protected function getFallbackUrlSafe(): string
    {
        try {
            return $this->getFallbackUrl();
        } catch (\Throwable $e) {
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg width="300" height="200" xmlns="http://www.w3.org/2000/svg">' .
                    '<rect width="100%" height="100%" fill="#f8f9fa"/>' .
                    '<text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#6c757d" font-family="Arial">No Image</text>' .
                    '</svg>'
            );
        }
    }

    // ==================== METODO PRINCIPALE OTTIMIZZATO ====================


    // ==================== METODI OTTIMIZZATI ====================

    /**
     * Cache key ottimizzata con CRC32
     */
    protected function getCacheKeyOptimized(?string $variant): string
    {
        $keyString = $this->configKey . ':' . $this->sourcePath . ':' . ($variant ?? 'main');
        $keyString .= ':' . $this->sourceDisk . ':' . serialize([
            $this->config[$this->configKey] ?? [], config('thumbnails.crop_mode'),
            config('thumbnails.dont_crop_background'), config('thumbnails.enable_smart_crop'),
        ]);
        return 'thumb_url:' . crc32($keyString);
    }

    /**
     * Get effective configuration with persistent cache using tags
     * Merges preset config with variant-specific settings if provided
     *
     * @param string|null $variant Optional variant name
     * @return array Merged configuration array
     */
    protected function getEffectiveConfigOptimized(?string $variant = null): array
    {
        $configKey = 'config:' . $this->configKey . ':' . ($variant ?? 'main');
        $configKey .= ':' . sha1(serialize($this->config[$this->configKey]));

        return ThumbnailIdentity::settings(CacheHelper::remember([self::CACHE_TAG, 'config'], $configKey, self::CACHE_TTL, function () use ($variant) {
            $config = $this->config[$this->configKey];
            if ($variant !== null) {
                if (!isset($config['variants'][$variant])) {
                    throw new \InvalidArgumentException("Thumbnail variant '{$variant}' not found.");
                }
                $config = array_replace_recursive($config, $config['variants'][$variant]);
            }
            return $config;
        }));
    }

    /**
     * Generate thumbnail path with persistent cache using tags
     * Caches the generated path to avoid recalculating subdirectories
     *
     * @param array $config Configuration array
     * @param string|null $variant Optional variant name
     * @return string Generated thumbnail path
     */
    protected function generateThumbnailPathOptimized(array $config, ?string $variant): string
    {
        return $this->generateThumbnailPath($config, $variant);
    }

    /**
     * Disk con cache statica (solo per durata richiesta)
     */
    protected function getDiskOptimized(string $diskName)
    {
        return Storage::disk($diskName);
    }

    /**
     * Check thumbnail existence with aggressive persistent cache
     * Uses cache tags for targeted invalidation
     *
     * @param mixed $disk Filesystem disk instance
     * @param string $path Thumbnail file path
     * @return bool True if thumbnail exists
     */
    protected function thumbnailExistsOptimized($disk, string $path): bool
    {
        return $disk->exists($path);
    }

    /**
     * Invalidate existence cache for a specific path
     *
     * @param string $path Thumbnail file path
     */
    protected function invalidateExistsCache(string $path): void
    {
        $existsKey = 'thumb_exists:' . crc32($path);
        CacheHelper::forget([self::CACHE_TAG, 'exists'], $existsKey);
    }

    /**
     * Set existence cache for a specific path
     *
     * @param string $path Thumbnail file path
     * @param bool $exists Whether the file exists
     */
    protected function setExistsCache(string $path, bool $exists): void
    {
        $existsKey = 'thumb_exists:' . crc32($path);
        CacheHelper::put([self::CACHE_TAG, 'exists'], $existsKey, $exists, self::CACHE_TTL);
    }

    /**
     * Override del metodo thumbnailExists originale
     */
    protected function thumbnailExists($disk, string $path): bool
    {
        return $this->thumbnailExistsOptimized($disk, $path);
    }

    /**
     * Logging ottimizzato
     */
    protected function logWarning(string $message, ?\Exception $e = null, ?string $variant = null): void
    {
        if (config('thumbnails.log_errors', true)) {
            try {
                Log::warning("ThumbnailService: {$message}", [
                    'error' => $e ? $e->getMessage() : 'No exception',
                    'config_key' => $this->configKey ?? 'unknown',
                    'source_path' => $this->sourcePath ?? 'unknown',
                    'variant' => $variant,
                    'silent_mode' => $this->silentMode,
                    'file_exists' => $this->sourcePath ? Storage::disk($this->sourceDisk)->exists($this->sourcePath) : false,
                    'request_url' => $this->getCurrentUrl(),
                ]);
            } catch (\Exception $logError) {
                // Ignora errori di log
            }
        }
    }

    protected function getCurrentUrl(): ?string
    {
        try {
            if (app()->runningInConsole()) {
                return 'console';
            }
            return request()->fullUrl();
        } catch (\Exception $e) {
            return $_SERVER['REQUEST_URI'] ?? null;
        }
    }

    protected function logTime($startTime, $operation, $variant = null): void
    {
        if (config('thumbnails.log_generation_time', false)) {
            $executionTime = microtime(true) - $startTime;
            Log::info('Thumbnail operation', [
                'operation' => $operation,
                'execution_time' => round($executionTime * 1000, 2) . 'ms',
                'config_key' => $this->configKey,
                'variant' => $variant,
                'request_url' => $this->getCurrentUrl(),
            ]);
        }
    }

    // ==================== OVERRIDE METODI ESISTENTI ====================


    /**
     * Override exists per usare cache ottimizzata
     */
    public function exists(?string $variant = null): bool
    {
        if (!$this->configKey || !$this->sourcePath) {
            return false;
        }

        try {
            $config = $this->getEffectiveConfigOptimized($variant);
            $this->sourceSnapshot = SourceImage::read($this->sourcePath, $this->sourceDisk, $config);
            $thumbnailPath = $this->generateThumbnailPathOptimized($config, $variant);
            $disk = $this->getDiskOptimized($config['destination']['disk']);
            return $this->thumbnailExistsOptimized($disk, $thumbnailPath);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Warm up cache per performance
     */
    public function warmUpCache(array $imagePaths, string $preset, array $variants = []): array
    {
        $results = ['warmed' => 0, 'errors' => 0, 'already_cached' => 0];
        foreach ($imagePaths as $imagePath) {
            foreach (array_merge([null], $variants) as $variant) {
                try {
                    $this->set($preset)->src($imagePath);
                    $config = $this->getEffectiveConfigOptimized($variant);
                    $path = $this->path($variant);
                    if (!Storage::disk($config['destination']['disk'])->exists($path)) {
                        continue;
                    }
                    $key = 'url:v3:' . hash('sha256', $config['destination']['disk'] . ':' . $path);
                    if (CacheHelper::has([self::CACHE_TAG, $preset], $key)) {
                        $results['already_cached']++;
                    } else {
                        $this->url($variant);
                        $results['warmed']++;
                    }
                } catch (\Exception $e) {
                    $results['errors']++;
                }
            }
        }
        return $results;
    }
    // ==================== TUTTI GLI ALTRI METODI RIMANGONO UGUALI ====================
    // (mantieni tutti i metodi esistenti senza modifiche)

    /**
     * Generate thumbnail with safe error handling
     * Uses Intervention Image 3.x API and ThumbnailGenerator utilities
     *
     * @param array $config Configuration array
     * @param string $thumbnailPath Destination thumbnail path
     * @throws \Exception If generation fails
     */
    /**
     * Refuses a source image with more pixels than the budget allows.
     *
     * The decoder allocates width x height x 4 bytes: a few MB of PNG can ask
     * for half a gigabyte and kill the request with "Allowed memory size
     * exhausted" — and a thumbnail is never worth a dead request. The
     * exception is caught upstream, so the caller falls back to the original
     * image.
     *
     * Set thumbnails.max_megapixels to 0 to disable the check.
     */
    protected function assertWithinPixelBudget(string $imageContent): void
    {
        $budget = PixelBudget::configuredBudget();

        if (!PixelBudget::exceeds($imageContent, $budget)) {
            return;
        }

        $megapixels = round((float) PixelBudget::megapixels($imageContent), 1);

        if (config('thumbnails.log_errors', true)) {
            Log::warning('Source image refused: too many pixels for a thumbnail', [
                'source' => $this->sourcePath,
                'disk' => $this->sourceDisk,
                'megapixels' => $megapixels,
                'budget_megapixels' => $budget,
            ]);
        }

        throw new \Exception(sprintf(
            'Source image too large to decode: %s megapixels, budget is %s (%s)',
            $megapixels,
            $budget,
            $this->sourcePath
        ));
    }

    protected function generateThumbnailSafe(array $config, string $thumbnailPath, Lock $lock, ?string $variant = null): void
    {
        $timeout = config('thumbnails.timeout', 30);
        set_time_limit($timeout);

        try {
            // Validate image content and extension
            ThumbnailGenerator::validateImageContent($this->sourcePath, null, $config);

            if (!Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
                throw new \Exception("Source image not found: {$this->sourcePath}");
            }

            $imageContent = ($this->sourceSnapshot ??= SourceImage::read($this->sourcePath, $this->sourceDisk, $config))['content'];
            if (empty($imageContent)) {
                throw new \Exception("Source image is empty: {$this->sourcePath}");
            }

            $this->assertWithinPixelBudget($imageContent);

            // Read image using Intervention Image 3.x
            $image = $this->imageManager->read($imageContent);

            [$width, $height] = explode('x', $config['smartcrop']);
            $width = (int) $width;
            $height = (int) $height;

            if ($width <= 0 || $height <= 0) {
                throw new \Exception("Invalid dimensions: {$config['smartcrop']}");
            }

            $image = ThumbnailGenerator::transform($image, $width, $height, $config, $this->smartCropService);

            $format = $config['format'] ?? config('thumbnails.default_format', 'jpg');
            $quality = $config['quality'] ?? config('thumbnails.default_quality', 85);

            $processedImage = ThumbnailGenerator::convertFormat($image, $format, $quality, $config);

            $record = ThumbnailManifest::record($this->sourcePath, $this->sourceDisk, $this->configKey, $variant, $config, $this->sourceSnapshot['hash']);
            ThumbnailPublisher::publish($config['destination']['disk'], $thumbnailPath, $processedImage, $lock, $record);

            // Cleanup memory (Intervention Image 3.x manages this automatically, but we can destroy explicitly)
            unset($image);
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors_full', true)) {
                Log::error('Thumbnail generation failed: ' . $e->getMessage(), [
                    'source' => $this->sourcePath,
                    'destination' => $thumbnailPath,
                    'config' => $config,
                    'trace' => $e->getTraceAsString(),
                    'request_url' => $this->getCurrentUrl(),
                ]);
            }
            throw $e;
        }
    }

    // Mantieni tutti gli altri metodi esistenti identici...
    protected function getEffectiveConfig(?string $variant = null): array
    {
        return $this->getEffectiveConfigOptimized($variant);
    }

    /**
     * Generate thumbnail file path with subdirectory organization
     * Uses ThumbnailGenerator for subdirectory calculation
     *
     * @param array $config Configuration array
     * @param string|null $variant Optional variant name
     * @return string Generated file path
     */
    protected function generateThumbnailPath(array $config, ?string $variant = null): string
    {
        $this->sourceSnapshot ??= SourceImage::read($this->sourcePath, $this->sourceDisk, $config);
        return ThumbnailIdentity::path(
            $this->sourcePath, $this->sourceDisk, $config, $variant, $this->sourceSnapshot['hash']
        );
    }

    /** Return the current content-addressed path without generating the thumbnail. */
    public function path(?string $variant = null): string
    {
        $config = $this->getEffectiveConfigOptimized($variant);
        $this->sourceSnapshot = SourceImage::read($this->sourcePath, $this->sourceDisk, $config);
        return $this->generateThumbnailPath($config, $variant);
    }

    public function regenerate(?string $variant = null): string
    {
        return $this->url($variant, true);
    }

    /** Actual output dimensions, suitable for responsive width descriptors. */
    public function imageData(?string $variant = null): array
    {
        return $this->resolveImageData($variant, false);
    }

    /** Verify source/output now, then refresh the successful metadata entry for subsequent callers. */
    public function imageDataFresh(?string $variant = null): array
    {
        return $this->resolveImageData($variant, true);
    }

    protected function resolveImageData(?string $variant, bool $fresh): array
    {
        if (!$this->configKey || !$this->sourcePath) {
            throw new \LogicException('A thumbnail preset and source must be selected before requesting image data.');
        }
        $ttl = filter_var(config('thumbnails.image_data_cache_ttl', 300), FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 0) {
            throw new \InvalidArgumentException('The image data cache TTL must be a non-negative integer.');
        }
        $enabled = config('thumbnails.cache_image_data', true) && config('thumbnails.cache_thumbnails', true) && $ttl > 0;
        $config = $this->getEffectiveConfigOptimized($variant);
        $tags = [self::CACHE_TAG, $this->configKey];
        if ($fresh) {
            // Advance the namespace before loading so earlier readers cannot overwrite this refresh.
            CacheHelper::flush([$this->configKey]);
        }
        $key = $enabled ? $this->imageDataCacheKey($config, $variant, $ttl) : null;
        if ($key !== null && !$fresh && ($cached = CacheHelper::get($tags, $key)) !== null) {
            return $cached;
        }

        // A fallback URL must never be paired with an old snapshot's dimensions or stored as success.
        $originalMode = $this->silentMode;
        $this->silentMode = false;
        try {
            $this->url($variant);
            $path = $this->generateThumbnailPath($config, $variant);
            $binary = Storage::disk($config['destination']['disk'])->get($path);
            $size = is_string($binary) ? @getimagesizefromstring($binary) : false;
            if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
                throw new \RuntimeException('Cannot determine generated thumbnail dimensions.');
            }
            // URL cache entries predate changes to a disk's URL/endpoint; compute the current public URL.
            $result = ['url' => ThumbnailGenerator::diskUrl($config['destination']['disk'], $path),
                'width' => $size[0], 'height' => $size[1]];
        } finally {
            $this->silentMode = $originalMode;
        }
        if ($key !== null) {
            CacheHelper::put($tags, $key, $result, $ttl);
        }
        return $result;
    }

    protected function imageDataCacheKey(array $config, ?string $variant, int $ttl): string
    {
        unset($config['variants']);
        $globals = [];
        foreach (['intervention_driver', 'default_subdirectory_strategy', 'sanitize_filenames',
            'max_file_size', 'max_megapixels', 'validate_image_content', 'allowed_extensions'] as $option) {
            $globals[$option] = config('thumbnails.' . $option);
        }
        return 'image_data:v1:' . hash('sha256', serialize([
            $this->configKey, $this->sourceDisk, $this->sourcePath, $variant, $config, $globals, $ttl,
            CacheHelper::namespaceFingerprint([self::CACHE_TAG, $this->configKey]),
            config('app.url'), $this->imageDataDiskFingerprint($this->sourceDisk),
            $this->imageDataDiskFingerprint($config['destination']['disk']),
        ]));
    }

    /** Include both configured scoped parents and the actual resolved adapter, without reading files. */
    protected function imageDataDiskFingerprint(string $name): array
    {
        $adapter = Storage::disk($name);
        $resolved = method_exists($adapter, 'getConfig') ? $adapter->getConfig() : null;
        $disks = [];
        while (!array_key_exists($name, $disks)) {
            $disk = config('filesystems.disks.' . $name, []);
            $disks[$name] = $disk;
            if (($disk['driver'] ?? null) !== 'scoped' || !is_string($disk['disk'] ?? null)) {
                break;
            }
            $name = $disk['disk'];
        }
        return ['class' => get_class($adapter), 'resolved' => $resolved, 'configured' => $disks];
    }

    protected function getFallbackUrl(): string
    {
        $placeholderUrl = config('thumbnails.placeholder_url');
        if ($placeholderUrl) {
            return $placeholderUrl;
        }

        if (config('thumbnails.fallback_to_original', false)) {
            try {
                if (Storage::disk($this->sourceDisk)->exists($this->sourcePath)) {
            return ThumbnailGenerator::diskUrl($this->sourceDisk, $this->sourcePath);
                }
            } catch (\Exception $e) {
                // Continua al placeholder generato
            }
        }

        if (config('thumbnails.generate_placeholders', true)) {
            return $this->generatePlaceholderUrl();
        }

        $staticPlaceholders = [
            '/images/no-image.png',
            '/images/placeholder.png',
            '/images/thumbnail-error.png',
            'data:image/svg+xml;base64,' . base64_encode('<svg width="300" height="200" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#6c757d">No Image</text></svg>'),
        ];

        return end($staticPlaceholders);
    }

    protected function generatePlaceholderUrl(): string
    {
        try {
            $config = $this->getEffectiveConfigOptimized();
            [$width, $height] = explode('x', $config['smartcrop']);

            $color = str_replace('#', '', config('thumbnails.placeholder_color', 'f8f9fa'));
            $textColor = str_replace('#', '', config('thumbnails.placeholder_text_color', '6c757d'));

            $placeholderServices = [
                "https://via.placeholder.com/{$width}x{$height}/{$color}/{$textColor}?text=No+Image",
                "https://picsum.photos/{$width}/{$height}?grayscale&blur=2",
                "https://source.unsplash.com/{$width}x{$height}/?grayscale",
            ];

            return $placeholderServices[0];
        } catch (\Exception $e) {
            return 'data:image/svg+xml;base64,' . base64_encode(
                '<svg width="300" height="200" xmlns="http://www.w3.org/2000/svg">' .
                    '<rect width="100%" height="100%" fill="#f8f9fa"/>' .
                    '<text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#6c757d">No Image</text>' .
                    '</svg>'
            );
        }
    }


    // ==================== UTILITIES MANTENUTE ====================
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

    public function getVariants(?string $configKey = null): array
    {
        $configKey = $configKey ?? $this->configKey;

        if (!$configKey || !isset($this->config[$configKey])) {
            return [];
        }

        return $this->config[$configKey]['variants'] ?? [];
    }

    public function isUpToDate(?string $variant = null): bool
    {
        return $this->exists($variant);
    }

    /**
     * Override Cache::forget per tracciare chi cancella le cache
     */
    protected function debugCacheForget(string $key, string $caller = ''): void
    {
        Log::error('🚨 CACHE BEING FORGOTTEN', [
            'key' => $key,
            'caller' => $caller,
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            'request_url' => $this->getCurrentUrl(),
        ]);

        Cache::forget($key);
    }

    /**
     * Override metodo url con debug completo
     */
    public function url(?string $variant = null, bool $force = false): string
    {
        if (!$this->configKey || !$this->sourcePath) {
            return $this->getFallbackUrl();
        }
        try {
            // Hash current bytes, not just size/mtime: replacements in the same second are detected.
            $config = $this->getEffectiveConfigOptimized($variant);
            $this->sourceSnapshot = SourceImage::read($this->sourcePath, $this->sourceDisk, $config);
            $path = $this->generateThumbnailPath($config, $variant);
            $disk = Storage::disk($config['destination']['disk']);
            $cacheKey = 'url:v3:' . hash('sha256', $config['destination']['disk'] . ':' . $path);
            $tags = [self::CACHE_TAG, $this->configKey];
            if ($force || !config('thumbnails.cache_thumbnails', true) || !$disk->exists($path)) {
                GenerationLock::run($config['destination']['disk'], $path, function (Lock $lock) use ($config, $path, $force, $variant, $disk) {
                    // A worker may have completed this thumbnail while this request waited for its lock.
                    if ($force || !config('thumbnails.cache_thumbnails', true) || !$disk->exists($path)) {
                        $this->generateThumbnailSafe($config, $path, $lock, $variant);
                    }
                });
                CacheHelper::forget($tags, $cacheKey);
            }
            if ($force) {
                CacheHelper::flush([$this->configKey]);
            }
            // Check the file even on cache hits: purges or external deletions cannot leave a stale URL.
            if (config('thumbnails.cache_urls', true) && ($url = CacheHelper::get($tags, $cacheKey))) {
                return $url;
            }
            $url = ThumbnailGenerator::diskUrl($config['destination']['disk'], $path);
            if (config('thumbnails.cache_urls', true)) {
                CacheHelper::put($tags, $cacheKey, $url, (int) config('thumbnails.cache_ttl', 3600));
            }
            return $url;
        } catch (\Exception $e) {
            $this->logWarning('Thumbnail failed, using fallback', $e, $variant);
            if (!$this->silentMode) {
                throw $e;
            }
            // Do not cache transient failures under a successful thumbnail identity.
            return $this->getFallbackUrl();
        }
    }

    /**
     * Clear cache for current thumbnail configuration
     * Uses cache tags for targeted invalidation
     */
    public function clearCache(): void
    {
        if ($this->configKey) {
            CacheHelper::flush([$this->configKey]);
        }
        $this->sourceSnapshot = null;
    }

    // Override di qualsiasi altro metodo che potrebbe fare Cache::forget
    public function regenerateIfNeeded(?string $variant = null): string
    {
        Log::info('🔄 Regenerate If Needed Called', [
            'variant' => $variant,
            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2),
        ]);

        if ($this->isUpToDate($variant)) {
            $config = $this->getEffectiveConfigOptimized($variant);
            $thumbnailPath = $this->generateThumbnailPathOptimized($config, $variant);
            return $this->getDiskOptimized($config['destination']['disk'])->url($thumbnailPath);
        }

        Log::warning('🔄 Cache being cleared in regenerateIfNeeded');
        $this->clearCache();
        return $this->url($variant);
    }


    // ==================== METODI LEGACY PER BACKWARD COMPATIBILITY ====================

    /**
     * Purge all thumbnails and clear related cache
     * Uses cache tags to avoid flushing the entire application cache
     *
     * @return int Number of thumbnails purged
     */
    public function purgeAll(): int
    {
        $count = 0;
        foreach (array_keys($this->config) as $preset) {
            $count += $this->purgePreset($preset);
        }
        CacheHelper::flush([self::CACHE_TAG]);
        return $count;
    }

    public function purgePreset(string $preset): int
    {
        if (!isset($this->config[$preset])) {
            throw new \InvalidArgumentException("Preset '{$preset}' not found");
        }
        $base = $this->config[$preset];
        $destinations = [$base['destination']];
        foreach ($base['variants'] ?? [] as $variant) {
            $destinations[] = array_replace($base['destination'], $variant['destination'] ?? []);
        }
        $count = 0;
        foreach (array_unique(array_map('serialize', $destinations)) as $serialized) {
            $destination = unserialize($serialized, ['allowed_classes' => false]);
            $disk = Storage::disk($destination['disk']);
            foreach ($disk->allFiles($destination['path']) as $path) {
                if ($this->isThumbnailFile($path)) {
                    $count += GenerationLock::run($destination['disk'], $path, function (Lock $lock) use ($disk, $path, $destination) {
                        if (!$disk->exists($path)) {
                            return 0;
                        }
                        GenerationLock::assertOwned($lock);
                        if (!$disk->delete($path)) {
                            throw new \RuntimeException("Could not delete thumbnail: {$path}");
                        }
                        $index = Storage::disk(ThumbnailManifest::disk());
                        $record = ThumbnailManifest::path($destination['disk'], $path);
                        if ($index->exists($record) && !$index->delete($record)) {
                            throw new \RuntimeException('Could not remove the purged thumbnail record.');
                        }
                        return 1;
                    });
                }
            }
            if (config('thumbnails.auto_cleanup_empty_dirs', true)) {
                $this->cleanEmptyDirectories($destination['disk'], $destination['path']);
            }
        }
        CacheHelper::flush([$preset]);
        $this->sourceSnapshot = null;
        return $count;
    }
    protected function cleanEmptyDirectories(string $disk, string $basePath, string $strategy = 'hash_prefix'): void
    {
        if ($strategy === 'none') {
            return;
        }

        try {
            $directories = Storage::disk($disk)->directories($basePath);

            foreach ($directories as $directory) {
                $this->cleanEmptyDirectoryRecursive($disk, $directory);
            }
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Could not clean empty directories", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function cleanEmptyDirectoryRecursive(string $disk, string $directory): void
    {
        try {
            $subdirectories = Storage::disk($disk)->directories($directory);
            foreach ($subdirectories as $subdir) {
                $this->cleanEmptyDirectoryRecursive($disk, $subdir);
            }

            $files = Storage::disk($disk)->files($directory);
            $dirs = Storage::disk($disk)->directories($directory);

            if (empty($files) && empty($dirs)) {
                Storage::disk($disk)->deleteDirectory($directory);
            }
        } catch (\Exception $e) {
            // Ignora errori
        }
    }

    protected function isThumbnailFile(string $filePath): bool
    {
        $filename = basename($filePath);
        return preg_match('/.*_\d+_\d+([_\w-]+)?\.(jpg|jpeg|png|webp|avif)$/i', $filename);
    }

    public function analyzeDistribution(string $preset): array
    {
        if (!isset($this->config[$preset])) {
            throw new \Exception("Preset '{$preset}' not found");
        }

        $config = $this->config[$preset];
        $disk = $config['destination']['disk'];
        $basePath = $config['destination']['path'];

        try {
            $files = Storage::disk($disk)->allFiles($basePath);
            $thumbnailFiles = array_filter($files, [$this, 'isThumbnailFile']);

            $distribution = [];
            $totalSize = 0;
            $sizeByFormat = [];

            foreach ($thumbnailFiles as $file) {
                $directory = dirname($file);
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                try {
                    $size = Storage::disk($disk)->size($file);
                } catch (\Exception $e) {
                    $size = 0;
                }

                if (!isset($distribution[$directory])) {
                    $distribution[$directory] = [
                        'count' => 0,
                        'size' => 0,
                    ];
                }
                $distribution[$directory]['count']++;
                $distribution[$directory]['size'] += $size;

                if (!isset($sizeByFormat[$extension])) {
                    $sizeByFormat[$extension] = [
                        'count' => 0,
                        'size' => 0,
                    ];
                }
                $sizeByFormat[$extension]['count']++;
                $sizeByFormat[$extension]['size'] += $size;

                $totalSize += $size;
            }

            return [
                'preset' => $preset,
                'total_files' => count($thumbnailFiles),
                'total_size' => $totalSize,
                'total_size_human' => ThumbnailGenerator::formatBytes($totalSize),
                'directories_count' => count($distribution),
                'average_per_directory' => count($distribution) > 0 ? round(count($thumbnailFiles) / count($distribution), 2) : 0,
                'distribution_by_directory' => $distribution,
                'distribution_by_format' => $sizeByFormat,
                'largest_directories' => $this->getLargestDirectories($distribution, 10),
                'strategy' => $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix'),
            ];
        } catch (\Exception $e) {
            throw new \Exception("Could not analyze distribution for preset '{$preset}': " . $e->getMessage());
        }
    }

    protected function getLargestDirectories(array $distribution, int $limit = 10): array
    {
        uasort($distribution, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($distribution, 0, $limit, true);
    }


    public function getSystemStats(): array
    {
        $stats = [
            'presets' => [],
            'total_files' => 0,
            'total_size' => 0,
            'disk_usage' => [],
        ];

        foreach (array_keys($this->config) as $preset) {
            try {
                $presetStats = $this->analyzeDistribution($preset);
                $stats['presets'][$preset] = $presetStats;
                $stats['total_files'] += $presetStats['total_files'];
                $stats['total_size'] += $presetStats['total_size'];

                $disk = $this->config[$preset]['destination']['disk'];
                if (!isset($stats['disk_usage'][$disk])) {
                    $stats['disk_usage'][$disk] = [
                        'files' => 0,
                        'size' => 0,
                        'presets' => [],
                    ];
                }
                $stats['disk_usage'][$disk]['files'] += $presetStats['total_files'];
                $stats['disk_usage'][$disk]['size'] += $presetStats['total_size'];
                $stats['disk_usage'][$disk]['presets'][] = $preset;
            } catch (\Exception $e) {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning("Could not get stats for preset '{$preset}': " . $e->getMessage());
                }
            }
        }

        $stats['total_size_human'] = ThumbnailGenerator::formatBytes($stats['total_size']);
        return $stats;
    }

    public function optimize(): array
    {
        $results = [
            'duplicates_removed' => 0,
            'orphaned_removed' => 0,
            'empty_dirs_removed' => 0,
            'space_freed' => 0,
        ];

        foreach ($this->config as $presetName => $config) {
            $disk = $config['destination']['disk'];
            $basePath = $config['destination']['path'];

            try {
                $duplicates = $this->findDuplicates($disk, $basePath);
                foreach ($duplicates as $duplicate) {
                    try {
                        $size = Storage::disk($disk)->size($duplicate);
                        Storage::disk($disk)->delete($duplicate);
                        $results['duplicates_removed']++;
                        $results['space_freed'] += $size;
                    } catch (\Exception $e) {
                        // Continua con il prossimo file
                    }
                }

                if (config('thumbnails.auto_cleanup_empty_dirs', true)) {
                    $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');
                    $emptyDirs = $this->findEmptyDirectories($disk, $basePath);
                    foreach ($emptyDirs as $dir) {
                        try {
                            Storage::disk($disk)->deleteDirectory($dir);
                            $results['empty_dirs_removed']++;
                        } catch (\Exception $e) {
                            // Continua con la prossima directory
                        }
                    }
                }
            } catch (\Exception $e) {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning("Could not optimize preset '{$presetName}': " . $e->getMessage());
                }
            }
        }

        $results['space_freed_human'] = ThumbnailGenerator::formatBytes($results['space_freed']);
        return $results;
    }

    protected function findDuplicates(string $disk, string $basePath): array
    {
        $files = Storage::disk($disk)->allFiles($basePath);
        $thumbnailFiles = array_filter($files, [$this, 'isThumbnailFile']);

        $hashes = [];
        $duplicates = [];

        foreach ($thumbnailFiles as $file) {
            try {
                $content = Storage::disk($disk)->get($file);
                $hash = md5($content);

                if (isset($hashes[$hash])) {
                    $duplicates[] = $file;
                } else {
                    $hashes[$hash] = $file;
                }
            } catch (\Exception $e) {
                // Ignora errori di lettura file
            }
        }

        return $duplicates;
    }

    protected function findEmptyDirectories(string $disk, string $basePath): array
    {
        $directories = Storage::disk($disk)->allDirectories($basePath);
        $emptyDirs = [];

        usort($directories, function ($a, $b) {
            return substr_count($b, '/') <=> substr_count($a, '/');
        });

        foreach ($directories as $directory) {
            try {
                $files = Storage::disk($disk)->files($directory);
                $subdirs = Storage::disk($disk)->directories($directory);

                if (empty($files) && empty($subdirs)) {
                    $emptyDirs[] = $directory;
                }
            } catch (\Exception $e) {
                // Ignora errori di accesso directory
            }
        }

        return $emptyDirs;
    }

    public function validateConfiguration(): array
    {
        $issues = [];

        foreach ($this->config as $presetName => $config) {
            $disk = $config['destination']['disk'];
            if (!config("filesystems.disks.{$disk}")) {
                $issues[] = "Preset '{$presetName}': destination disk '{$disk}' is not configured";
            }

            if (!isset($config['smartcrop']) || !preg_match('/^\d+x\d+$/', $config['smartcrop'])) {
                $issues[] = "Preset '{$presetName}': invalid smartcrop format '{$config['smartcrop']}'";
            }

            if (isset($config['variants'])) {
                foreach ($config['variants'] as $variantName => $variantConfig) {
                    if (isset($variantConfig['smartcrop']) && !preg_match('/^\d+x\d+$/', $variantConfig['smartcrop'])) {
                        $issues[] = "Preset '{$presetName}', variant '{$variantName}': invalid smartcrop format";
                    }
                }
            }

            $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');
            $validStrategies = ['hash_prefix', 'date_based', 'filename_prefix', 'hash_levels', 'none'];
            if (!in_array($strategy, $validStrategies)) {
                $issues[] = "Preset '{$presetName}': invalid subdirectory strategy '{$strategy}'";
            }
        }

        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'presets_count' => count($this->config),
            'total_variants' => $this->countTotalVariants(),
        ];
    }

    protected function countTotalVariants(): int
    {
        $total = 0;
        foreach ($this->config as $config) {
            $total += count($config['variants'] ?? []);
        }
        return $total;
    }

    public function testSubdirectoryStrategies(string $filename = 'test-image'): array
    {
        $results = [];
        $strategies = ['hash_prefix', 'date_based', 'filename_prefix', 'hash_levels', 'none'];

        foreach ($strategies as $strategy) {
            try {
                $config = ['subdirectory_strategy' => $strategy];
                $subdir = ThumbnailGenerator::generateSubdirectory($filename, $config);

                $results[$strategy] = [
                    'success' => true,
                    'subdirectory' => $subdir,
                    'error' => null,
                ];

                if ($this->configKey) {
                    $presetConfig = $this->getEffectiveConfigOptimized();
                    $testPath = $presetConfig['destination']['path'] . $subdir;

                    try {
                        ThumbnailGenerator::ensureDestinationDirectory($presetConfig['destination']['disk'], $testPath);
                        $results[$strategy]['directory_creation'] = 'success';
                    } catch (\Exception $dirError) {
                        $results[$strategy]['directory_creation'] = 'failed: ' . $dirError->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $results[$strategy] = [
                    'success' => false,
                    'subdirectory' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    public function debugInfo(?string $variant = null): array
    {
        if (!$this->configKey || !$this->sourcePath) {
            return ['error' => 'Configuration key and source path must be set'];
        }

        try {
            $config = $this->getEffectiveConfigOptimized($variant);
            $thumbnailPath = $this->generateThumbnailPathOptimized($config, $variant);
            $disk = $this->getDiskOptimized($config['destination']['disk']);

            $info = [
                'config_key' => $this->configKey,
                'variant' => $variant,
                'source_path' => $this->sourcePath,
                'source_disk' => $this->sourceDisk,
                'source_exists' => Storage::disk($this->sourceDisk)->exists($this->sourcePath),
                'thumbnail_path' => $thumbnailPath,
                'destination_disk' => $config['destination']['disk'],
                'thumbnail_exists' => $disk->exists($thumbnailPath),
                'subdirectory_strategy' => $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix'),
                'dimensions' => $config['smartcrop'],
                'format' => $config['format'] ?? config('thumbnails.default_format', 'jpg'),
                'quality' => $config['quality'] ?? config('thumbnails.default_quality', 85),
                'smart_crop_enabled' => $config['smart_crop_enabled'] ?? config('thumbnails.enable_smart_crop', true),
            ];

            $pathInfo = pathinfo($this->sourcePath);
            $filename = $pathInfo['filename'];
            $subdir = ThumbnailGenerator::generateSubdirectory($filename, $config);
            $info['generated_subdirectory'] = $subdir;

            try {
                ThumbnailGenerator::ensureDestinationDirectory($config['destination']['disk'], dirname($thumbnailPath));
                $info['directory_creation'] = 'success';
            } catch (\Exception $dirError) {
                $info['directory_creation'] = 'failed: ' . $dirError->getMessage();
            }

            if ($info['thumbnail_exists']) {
                try {
                    $info['thumbnail_url'] = $disk->url($thumbnailPath);
                    $info['thumbnail_size'] = $disk->size($thumbnailPath);
                    $info['thumbnail_last_modified'] = date('Y-m-d H:i:s', $disk->lastModified($thumbnailPath));
                } catch (\Exception $e) {
                    $info['thumbnail_info_error'] = $e->getMessage();
                }
            }

            return $info;
        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'config_key' => $this->configKey,
                'source_path' => $this->sourcePath,
            ];
        }
    }

    public function batchGenerate(array $images, string $preset, array $variants = []): array
    {
        $results = [
            'generated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($images as $imagePath) {
            try {
                $this->set($preset)->src($imagePath);

                $url = $this->url();
                $results['generated']++;
                $results['details'][$imagePath]['main'] = $url;

                foreach ($variants as $variant) {
                    try {
                        $variantUrl = $this->url($variant);
                        $results['details'][$imagePath]['variants'][$variant] = $variantUrl;
                    } catch (\Exception $e) {
                        $results['errors']++;
                        $results['details'][$imagePath]['errors'][$variant] = $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $results['errors']++;
                $results['details'][$imagePath]['error'] = $e->getMessage();
            }
        }

        return $results;
    }

    public function getPerformanceMetrics(?string $variant = null): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $url = $this->url($variant);

            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);

            return [
                'success' => true,
                'url' => $url,
                'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                'memory_used_bytes' => $endMemory - $startMemory,
                'memory_used_human' => ThumbnailGenerator::formatBytes($endMemory - $startMemory),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'peak_memory_human' => ThumbnailGenerator::formatBytes(memory_get_peak_usage(true)),
                'config_key' => $this->configKey,
                'variant' => $variant,
                'cache_enabled' => config('thumbnails.cache_urls', true),
            ];
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
                'memory_used_bytes' => $endMemory - $startMemory,
                'memory_used_human' => ThumbnailGenerator::formatBytes($endMemory - $startMemory),
                'config_key' => $this->configKey,
                'variant' => $variant,
            ];
        }
    }
    protected function generateCacheKey(string $path, ?string $variant = null): string
    {
        $normalizedPath = strtolower(trim($path));
        $normalizedVariant = strtolower(trim($variant ?? 'default'));
        return self::CACHE_PREFIX . crc32($normalizedPath . ':' . $normalizedVariant);
    }
}
