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
    protected $silentMode;

    public function __construct(SmartCropService $smartCropService)
    {
        $this->config = config('thumbnails.presets', []);
        $this->smartCropService = $smartCropService;
        $this->silentMode = config('thumbnails.silent_mode_default', true);

        // Configura Intervention Image
        $this->configureInterventionImage();
    }

    /**
     * Configura Intervention Image con le impostazioni del config
     */
    protected function configureInterventionImage(): void
    {
        $driver = config('thumbnails.intervention_driver', 'gd');
        Image::configure(['driver' => $driver]);

        // Imposta memory limit se configurato
        $memoryLimit = config('thumbnails.memory_limit');
        if ($memoryLimit) {
            ini_set('memory_limit', $memoryLimit);
        }
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

    public function urlSafe(string $variant = null): string
    {
        $originalMode = $this->silentMode;
        $this->silentMode = true;

        try {
            $result = $this->url($variant);
            $this->silentMode = $originalMode;
            return $result;
        } catch (\Exception $e) {
            $this->silentMode = $originalMode;

            if (config('thumbnails.log_errors', true)) {
                Log::error('ThumbnailService::urlSafe() failed', [
                    'error' => $e->getMessage(),
                    'config_key' => $this->configKey,
                    'source_path' => $this->sourcePath,
                    'variant' => $variant
                ]);
            }

            return $this->getFallbackUrl();
        }
    }

    public function url(string $variant = null): string
    {
        try {
            $startTime = microtime(true);

            if (!$this->configKey || !$this->sourcePath) {
                throw new \Exception("Configuration key and source path must be set");
            }

            // Valida sicurezza del file se abilitata
            $this->validateImageSecurity();

            $this->validateSourceDisk();
            $config = $this->getEffectiveConfig($variant);
            $thumbnailPath = $this->generateThumbnailPath($config, $variant);

            if ($this->thumbnailExists($config['destination']['disk'], $thumbnailPath)) {
                $url = $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
            } else {
                $this->generateThumbnail($config, $thumbnailPath);
                $url = $this->getThumbnailUrl($config['destination']['disk'], $thumbnailPath);
            }

            // Log tempo di generazione se abilitato
            if (config('thumbnails.log_generation_time', false)) {
                $executionTime = microtime(true) - $startTime;
                Log::info('Thumbnail generated', [
                    'execution_time' => round($executionTime * 1000, 2) . 'ms',
                    'config_key' => $this->configKey,
                    'variant' => $variant
                ]);
            }

            return $url;
        } catch (\Exception $e) {
            if ($this->silentMode) {
                return $this->getFallbackUrl();
            }
            throw $e;
        }
    }

    /**
     * Valida sicurezza dell'immagine
     */
    protected function validateImageSecurity(): void
    {
        if (!config('thumbnails.validate_image_content', true)) {
            return;
        }

        $pathInfo = pathinfo($this->sourcePath);
        $extension = strtolower($pathInfo['extension'] ?? '');

        $allowedExtensions = config('thumbnails.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);

        if (!in_array($extension, $allowedExtensions)) {
            throw new \Exception("File extension '{$extension}' not allowed");
        }

        // Verifica dimensione file se configurata
        $maxFileSize = config('thumbnails.max_file_size');
        if ($maxFileSize) {
            try {
                $fileSize = Storage::disk($this->sourceDisk)->size($this->sourcePath);
                if ($fileSize > $maxFileSize) {
                    throw new \Exception("File size ({$fileSize} bytes) exceeds maximum allowed ({$maxFileSize} bytes)");
                }
            } catch (\Exception $e) {
                // Se non riesce a ottenere la dimensione, continua (potrebbe essere normale)
            }
        }
    }

    /**
     * Ottiene URL di fallback in caso di errore
     */
    protected function getFallbackUrl(): string
    {
        // 1. Prova URL placeholder personalizzato
        $placeholderUrl = config('thumbnails.placeholder_url');
        if ($placeholderUrl) {
            return $placeholderUrl;
        }

        // 2. Prova fallback all'immagine originale
        if (config('thumbnails.fallback_to_original', false)) {
            try {
                return Storage::disk($this->sourceDisk)->url($this->sourcePath);
            } catch (\Exception $e) {
                // Continua al placeholder generato
            }
        }

        // 3. Genera placeholder se abilitato
        if (config('thumbnails.generate_placeholders', true)) {
            return $this->generatePlaceholderUrl();
        }

        // 4. Fallback finale
        return '/images/thumbnail-error.png';
    }

    /**
     * Genera URL placeholder
     */
    protected function generatePlaceholderUrl(): string
    {
        try {
            $config = $this->getEffectiveConfig();
            [$width, $height] = explode('x', $config['smartcrop']);

            // Genera un placeholder colorato
            $color = config('thumbnails.placeholder_color', '#f8f9fa');
            $textColor = config('thumbnails.placeholder_text_color', '#6c757d');

            // Per ora ritorna un URL di servizio placeholder
            // Potresti implementare un controller che genera placeholder dinamici
            return "https://via.placeholder.com/{$width}x{$height}/{$color}/{$textColor}?text=No+Image";
        } catch (\Exception $e) {
            return '/images/thumbnail-error.png';
        }
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
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Error checking thumbnail existence: " . $e->getMessage());
            }
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

    /**
     * Genera il percorso del thumbnail con subdirectory automatiche
     */
    protected function generateThumbnailPath(array $config, string $variant = null): string
    {
        $pathInfo = pathinfo($this->sourcePath);
        $filename = $pathInfo['filename'];

        // Sanifica il filename se abilitato
        if (config('thumbnails.sanitize_filenames', true)) {
            $filename = $this->sanitizeFilename($filename);
        }

        $format = $config['format'] ?? config('thumbnails.default_format', 'jpg');

        $suffix = str_replace('x', '_', $config['smartcrop']);

        if ($variant) {
            $suffix .= '_' . $variant;
        }

        // Genera subdirectory basata su strategia configurata
        $subdirectory = $this->generateSubdirectory($filename, $config);

        return $config['destination']['path'] .
            $subdirectory .
            $filename . '_' .
            $suffix .
            '.' . $format;
    }

    /**
     * Sanifica il nome del file
     */
    protected function sanitizeFilename(string $filename): string
    {
        // Rimuovi caratteri speciali pericolosi
        $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '', $filename);

        // Limita lunghezza
        return substr($filename, 0, 100);
    }

    /**
     * Genera subdirectory automatica per organizzare i thumbnail
     */
    protected function generateSubdirectory(string $filename, array $config): string
    {
        $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');

        switch ($strategy) {
            case 'hash_prefix':
                return $this->generateHashPrefixSubdirectory($filename);
            case 'date_based':
                return $this->generateDateBasedSubdirectory();
            case 'filename_prefix':
                return $this->generateFilenamePrefixSubdirectory($filename);
            case 'hash_levels':
                return $this->generateHashLevelsSubdirectory($filename);
            case 'none':
            default:
                return '';
        }
    }

    protected function generateHashPrefixSubdirectory(string $filename): string
    {
        $hash = md5($filename);
        $level1 = substr($hash, 0, 1);
        $level2 = substr($hash, 1, 1);
        return $level1 . '/' . $level2 . '/';
    }

    protected function generateDateBasedSubdirectory(): string
    {
        return now()->format('Y/m/d') . '/';
    }

    protected function generateFilenamePrefixSubdirectory(string $filename): string
    {
        $clean = preg_replace('/[^a-z0-9]/i', '', strtolower($filename));

        if (strlen($clean) < 2) {
            return 'misc/';
        }

        $level1 = substr($clean, 0, 1);
        $level2 = strlen($clean) > 1 ? substr($clean, 1, 1) : '0';

        return $level1 . '/' . $level2 . '/';
    }

    protected function generateHashLevelsSubdirectory(string $filename): string
    {
        $hash = md5($filename);
        $level1 = substr($hash, 0, 1);
        $level2 = substr($hash, 1, 1);
        $level3 = substr($hash, 2, 1);
        return $level1 . '/' . $level2 . '/' . $level3 . '/';
    }

    protected function generateThumbnail(array $config, string $thumbnailPath): void
    {
        $timeout = config('thumbnails.timeout', 30);
        set_time_limit($timeout);

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

            $processedImage = $this->convertFormatWithOptimizations($image, $format, $quality);

            $this->ensureDestinationDirectory($config['destination']['disk'], dirname($thumbnailPath));

            Storage::disk($config['destination']['disk'])
                ->put($thumbnailPath, $processedImage);
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::error('Thumbnail generation failed: ' . $e->getMessage(), [
                    'source' => $this->sourcePath,
                    'destination' => $thumbnailPath,
                    'trace' => $e->getTraceAsString()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Converte formato con ottimizzazioni avanzate
     */
    protected function convertFormatWithOptimizations($image, string $format, int $quality = 85)
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
                $progressive = config('thumbnails.enable_progressive_jpeg', true);
                $encoded = $image->encode('jpg', $quality);

                if ($progressive) {
                    // Note: Intervention Image 2.x non supporta direttamente JPEG progressivi
                    // Potresti implementare post-processing se necessario
                }

                return $encoded->encoded;
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
            $directory = $this->normalizePath($directory);

            if (!Storage::disk($disk)->exists($directory)) {
                Storage::disk($disk)->makeDirectory($directory, 0755, true);
            }
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Could not create directory: " . $e->getMessage());
            }
        }
    }

    protected function normalizePath(string $path): string
    {
        $path = preg_replace('/\/+/', '/', $path);
        $path = rtrim($path, '/');
        $path = ltrim($path, '/');
        return $path;
    }

    // ... metodi utility rimangono come prima ...

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

                // Pulisci directory vuote se abilitato
                if (config('thumbnails.auto_cleanup_empty_dirs', true)) {
                    $this->cleanEmptyDirectories($disk, $path);
                }
            } catch (\Exception $e) {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning("Could not purge thumbnails from {$disk}:{$path} - " . $e->getMessage());
                }
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
        $basePath = $config['destination']['path'];
        $purgedCount = 0;

        try {
            $files = Storage::disk($disk)->allFiles($basePath);

            foreach ($files as $file) {
                if ($this->isThumbnailFile($file)) {
                    Storage::disk($disk)->delete($file);
                    $purgedCount++;
                }
            }

            // Pulisci directory vuote se abilitato
            if (config('thumbnails.auto_cleanup_empty_dirs', true)) {
                $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');
                $this->cleanEmptyDirectories($disk, $basePath, $strategy);
            }
        } catch (\Exception $e) {
            throw new \Exception("Could not purge preset '{$preset}': " . $e->getMessage());
        }

        return $purgedCount;
    }

    /**
     * Rimuove directory vuote dopo il purge
     */
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
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Pulisce ricorsivamente le directory vuote
     */
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

                if (config('thumbnails.log_errors', true)) {
                    Log::info("Removed empty directory", ['directory' => $directory]);
                }
            }
        } catch (\Exception $e) {
            if (config('thumbnails.log_errors', true)) {
                Log::warning("Could not clean directory", [
                    'directory' => $directory,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function isThumbnailFile(string $filePath): bool
    {
        $filename = basename($filePath);
        return preg_match('/.*_\d+_\d+(_\w+)?\.(jpg|jpeg|png|webp)$/i', $filename);
    }

    /**
     * Analizza la distribuzione dei file per un preset
     */
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
                    $size = 0; // Se non riesce a ottenere la dimensione
                }

                // Distribuzione per directory
                if (!isset($distribution[$directory])) {
                    $distribution[$directory] = [
                        'count' => 0,
                        'size' => 0
                    ];
                }
                $distribution[$directory]['count']++;
                $distribution[$directory]['size'] += $size;

                // Distribuzione per formato
                if (!isset($sizeByFormat[$extension])) {
                    $sizeByFormat[$extension] = [
                        'count' => 0,
                        'size' => 0
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
                'total_size_human' => $this->formatBytes($totalSize),
                'directories_count' => count($distribution),
                'average_per_directory' => count($distribution) > 0 ? round(count($thumbnailFiles) / count($distribution), 2) : 0,
                'distribution_by_directory' => $distribution,
                'distribution_by_format' => $sizeByFormat,
                'largest_directories' => $this->getLargestDirectories($distribution, 10),
                'strategy' => $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix')
            ];
        } catch (\Exception $e) {
            throw new \Exception("Could not analyze distribution for preset '{$preset}': " . $e->getMessage());
        }
    }

    /**
     * Ottiene le directory più grandi
     */
    protected function getLargestDirectories(array $distribution, int $limit = 10): array
    {
        uasort($distribution, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return array_slice($distribution, 0, $limit, true);
    }

    /**
     * Formatta i byte in formato leggibile
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Ottiene statistiche complete del sistema thumbnail
     */
    public function getSystemStats(): array
    {
        $stats = [
            'presets' => [],
            'total_files' => 0,
            'total_size' => 0,
            'disk_usage' => []
        ];

        foreach (array_keys($this->config) as $preset) {
            try {
                $presetStats = $this->analyzeDistribution($preset);
                $stats['presets'][$preset] = $presetStats;
                $stats['total_files'] += $presetStats['total_files'];
                $stats['total_size'] += $presetStats['total_size'];

                // Statistiche per disco
                $disk = $this->config[$preset]['destination']['disk'];
                if (!isset($stats['disk_usage'][$disk])) {
                    $stats['disk_usage'][$disk] = [
                        'files' => 0,
                        'size' => 0,
                        'presets' => []
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

        $stats['total_size_human'] = $this->formatBytes($stats['total_size']);

        return $stats;
    }

    /**
     * Ottimizza il sistema rimuovendo duplicati e file orfani
     */
    public function optimize(): array
    {
        $results = [
            'duplicates_removed' => 0,
            'orphaned_removed' => 0,
            'empty_dirs_removed' => 0,
            'space_freed' => 0
        ];

        foreach ($this->config as $presetName => $config) {
            $disk = $config['destination']['disk'];
            $basePath = $config['destination']['path'];

            try {
                // Trova e rimuovi duplicati
                $duplicates = $this->findDuplicates($disk, $basePath);
                foreach ($duplicates as $duplicate) {
                    $size = Storage::disk($disk)->size($duplicate);
                    Storage::disk($disk)->delete($duplicate);
                    $results['duplicates_removed']++;
                    $results['space_freed'] += $size;
                }

                // Pulisci directory vuote
                if (config('thumbnails.auto_cleanup_empty_dirs', true)) {
                    $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');
                    $emptyDirs = $this->findEmptyDirectories($disk, $basePath);
                    foreach ($emptyDirs as $dir) {
                        Storage::disk($disk)->deleteDirectory($dir);
                        $results['empty_dirs_removed']++;
                    }
                }
            } catch (\Exception $e) {
                if (config('thumbnails.log_errors', true)) {
                    Log::warning("Could not optimize preset '{$presetName}': " . $e->getMessage());
                }
            }
        }

        $results['space_freed_human'] = $this->formatBytes($results['space_freed']);

        return $results;
    }

    /**
     * Trova file duplicati (stesso contenuto)
     */
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
                    $duplicates[] = $file; // Il file corrente è un duplicato
                } else {
                    $hashes[$hash] = $file; // Primo file con questo hash
                }
            } catch (\Exception $e) {
                // Ignora errori di lettura file
            }
        }

        return $duplicates;
    }

    /**
     * Trova directory vuote
     */
    protected function findEmptyDirectories(string $disk, string $basePath): array
    {
        $directories = Storage::disk($disk)->allDirectories($basePath);
        $emptyDirs = [];

        // Ordina per profondità (prima le più profonde)
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

    /**
     * Valida la configurazione del sistema
     */
    public function validateConfiguration(): array
    {
        $issues = [];

        foreach ($this->config as $presetName => $config) {
            // Verifica disco di destinazione
            $disk = $config['destination']['disk'];
            if (!config("filesystems.disks.{$disk}")) {
                $issues[] = "Preset '{$presetName}': destination disk '{$disk}' is not configured";
            }

            // Verifica formato smartcrop
            if (!isset($config['smartcrop']) || !preg_match('/^\d+x\d+$/', $config['smartcrop'])) {
                $issues[] = "Preset '{$presetName}': invalid smartcrop format '{$config['smartcrop']}'";
            }

            // Verifica varianti
            if (isset($config['variants'])) {
                foreach ($config['variants'] as $variantName => $variantConfig) {
                    if (isset($variantConfig['smartcrop']) && !preg_match('/^\d+x\d+$/', $variantConfig['smartcrop'])) {
                        $issues[] = "Preset '{$presetName}', variant '{$variantName}': invalid smartcrop format";
                    }
                }
            }

            // Verifica strategia subdirectory
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
            'total_variants' => $this->countTotalVariants()
        ];
    }

    /**
     * Conta il numero totale di varianti
     */
    protected function countTotalVariants(): int
    {
        $total = 0;
        foreach ($this->config as $config) {
            $total += count($config['variants'] ?? []);
        }
        return $total;
    }
}
