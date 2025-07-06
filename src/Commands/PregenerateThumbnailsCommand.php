.<?php

    namespace Askancy\LaravelSmartThumbnails\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\DB;
    use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
    use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;

    class PregenerateThumbnailsCommand extends Command
    {
        protected $signature = 'thumbnails:pregenerate 
                           {preset? : Specific preset to pregenerate}
                           {--disk=public : Source disk to scan}
                           {--path= : Specific path to scan (default: images/)}
                           {--async : Generate thumbnails asynchronously}
                           {--force : Regenerate existing thumbnails}
                           {--chunk=50 : Number of images to process in each chunk}
                           {--dry-run : Show what would be generated without actually generating}';

        protected $description = 'Pre-generate thumbnails for existing images';

        protected $thumbnailService;

        public function __construct(ThumbnailService $thumbnailService)
        {
            parent::__construct();
            $this->thumbnailService = $thumbnailService;
        }

        public function handle()
        {
            $preset = $this->argument('preset');
            $sourceDisk = $this->option('disk');
            $scanPath = $this->option('path') ?: 'images/';
            $async = $this->option('async');
            $force = $this->option('force');
            $chunkSize = (int) $this->option('chunk');
            $dryRun = $this->option('dry-run');

            // Configura memory limit
            $memoryLimit = config('thumbnails.commands.pregenerate.memory_limit', '512M');
            ini_set('memory_limit', $memoryLimit);

            $this->info("🚀 Starting thumbnail pre-generation...");
            $this->info("📁 Source disk: {$sourceDisk}");
            $this->info("📂 Scan path: {$scanPath}");

            if ($dryRun) {
                $this->warn("🧪 DRY RUN MODE - No thumbnails will be generated");
            }

            // Ottieni configurazioni preset
            $presets = $preset ? [$preset => config("thumbnails.presets.{$preset}")] : config('thumbnails.presets', []);

            if (empty($presets)) {
                $this->error('❌ No presets found or invalid preset specified');
                return 1;
            }

            if ($preset && !isset($presets[$preset])) {
                $this->error("❌ Preset '{$preset}' not found");
                return 1;
            }

            $this->info("🎯 Presets to process: " . implode(', ', array_keys($presets)));

            // Scansiona immagini esistenti
            $this->info("🔍 Scanning for images in {$sourceDisk}:{$scanPath}...");

            $images = $this->scanForImages($sourceDisk, $scanPath);

            if (empty($images)) {
                $this->warn('⚠️  No images found to process');
                return 0;
            }

            $this->info("📸 Found " . count($images) . " images");

            $totalGenerated = 0;
            $totalSkipped = 0;
            $totalErrors = 0;

            // Progresso generale
            $progressBar = $this->output->createProgressBar(count($images) * count($presets));
            $progressBar->setFormat('detailed');

            foreach ($presets as $presetName => $presetConfig) {
                $this->newLine();
                $this->info("📝 Processing preset: {$presetName}");

                // Processa in chunks per ottimizzare memoria
                $chunks = array_chunk($images, $chunkSize);

                foreach ($chunks as $chunkIndex => $imageChunk) {
                    $this->info("📦 Processing chunk " . ($chunkIndex + 1) . "/" . count($chunks) . " (" . count($imageChunk) . " images)");

                    foreach ($imageChunk as $imagePath) {
                        $progressBar->advance();

                        try {
                            $result = $this->processImage($imagePath, $sourceDisk, $presetName, $presetConfig, $async, $force, $dryRun);

                            if ($result['generated']) {
                                $totalGenerated += $result['count'];
                            } else {
                                $totalSkipped += $result['count'];
                            }
                        } catch (\Exception $e) {
                            $totalErrors++;
                            $this->error("❌ Error processing {$imagePath}: " . $e->getMessage());

                            if ($this->option('verbose')) {
                                $this->line($e->getTraceAsString());
                            }
                        }

                        // Libera memoria tra le immagini
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }

                    // Pausa breve tra i chunks per evitare sovraccarico
                    if (count($chunks) > 1) {
                        usleep(100000); // 0.1 secondi
                    }
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // Statistiche finali
            $this->info("✅ Pre-generation completed!");
            $this->table(['Metric', 'Count'], [
                ['Generated', $totalGenerated],
                ['Skipped', $totalSkipped],
                ['Errors', $totalErrors],
                ['Total Images', count($images)],
                ['Total Presets', count($presets)],
            ]);

            if ($async && $totalGenerated > 0) {
                $this->info("🔄 {$totalGenerated} thumbnails queued for async generation");
                $this->info("💡 Check queue status with: php artisan queue:work");
            }

            return $totalErrors > 0 ? 1 : 0;
        }

        protected function scanForImages(string $disk, string $path): array
        {
            try {
                $allowedExtensions = config('thumbnails.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                $files = Storage::disk($disk)->allFiles($path);

                $images = array_filter($files, function ($file) use ($allowedExtensions) {
                    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    return in_array($extension, $allowedExtensions);
                });

                return array_values($images);
            } catch (\Exception $e) {
                $this->error("❌ Error scanning disk {$disk}:{$path} - " . $e->getMessage());
                return [];
            }
        }

        protected function processImage(
            string $imagePath,
            string $sourceDisk,
            string $presetName,
            array $presetConfig,
            bool $async,
            bool $force,
            bool $dryRun
        ): array {
            $generated = 0;
            $skipped = 0;

            try {
                $this->thumbnailService
                    ->set($presetName)
                    ->src($imagePath, $sourceDisk);

                // Genera thumbnail principale
                $mainThumbnailPath = $this->generateThumbnailPath($imagePath, $presetConfig);

                if ($force || !$this->thumbnailExists($presetConfig['destination']['disk'], $mainThumbnailPath)) {
                    if (!$dryRun) {
                        if ($async) {
                            $this->dispatchThumbnailJob($imagePath, $sourceDisk, $presetName, $presetConfig, $mainThumbnailPath);
                        } else {
                            $this->thumbnailService->url();
                        }
                    }
                    $generated++;

                    if ($this->option('verbose')) {
                        $this->line("  ✓ Generated: {$mainThumbnailPath}");
                    }
                } else {
                    $skipped++;

                    if ($this->option('verbose')) {
                        $this->line("  ⏭ Skipped: {$mainThumbnailPath} (already exists)");
                    }
                }

                // Genera varianti
                if (isset($presetConfig['variants'])) {
                    foreach ($presetConfig['variants'] as $variantName => $variantConfig) {
                        $effectiveConfig = array_merge($presetConfig, $variantConfig);
                        $variantThumbnailPath = $this->generateThumbnailPath($imagePath, $effectiveConfig, $variantName);

                        if ($force || !$this->thumbnailExists($effectiveConfig['destination']['disk'] ?? $presetConfig['destination']['disk'], $variantThumbnailPath)) {
                            if (!$dryRun) {
                                if ($async) {
                                    $this->dispatchThumbnailJob($imagePath, $sourceDisk, $presetName, $effectiveConfig, $variantThumbnailPath, $variantName);
                                } else {
                                    $this->thumbnailService->url($variantName);
                                }
                            }
                            $generated++;

                            if ($this->option('verbose')) {
                                $this->line("  ✓ Generated variant: {$variantThumbnailPath}");
                            }
                        } else {
                            $skipped++;

                            if ($this->option('verbose')) {
                                $this->line("  ⏭ Skipped variant: {$variantThumbnailPath} (already exists)");
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                throw $e;
            }

            return [
                'generated' => $generated > 0,
                'count' => $generated + $skipped,
                'generated_count' => $generated,
                'skipped_count' => $skipped
            ];
        }

        protected function dispatchThumbnailJob(
            string $imagePath,
            string $sourceDisk,
            string $presetName,
            array $config,
            string $thumbnailPath,
            string $variant = null
        ): void {
            GenerateThumbnailJob::dispatch(
                $imagePath,
                $sourceDisk,
                $presetName,
                $config,
                $thumbnailPath,
                $variant
            )->onQueue(config('thumbnails.queue_name', 'thumbnails'));
        }

        protected function generateThumbnailPath(string $imagePath, array $config, string $variant = null): string
        {
            $pathInfo = pathinfo($imagePath);
            $filename = $pathInfo['filename'];

            if (config('thumbnails.sanitize_filenames', true)) {
                $filename = preg_replace('/[^a-zA-Z0-9\-_]/', '', $filename);
                $filename = substr($filename, 0, 100);
            }

            $format = $config['format'] ?? config('thumbnails.default_format', 'jpg');
            $suffix = str_replace('x', '_', $config['smartcrop']);

            if ($variant) {
                $suffix .= '_' . $variant;
            }

            $subdirectory = $this->generateSubdirectory($filename, $config);

            return $config['destination']['path'] .
                $subdirectory .
                $filename . '_' .
                $suffix .
                '.' . $format;
        }

        protected function generateSubdirectory(string $filename, array $config): string
        {
            $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');

            switch ($strategy) {
                case 'hash_prefix':
                    $hash = md5($filename);
                    return substr($hash, 0, 1) . '/' . substr($hash, 1, 1) . '/';
                case 'date_based':
                    return now()->format('Y/m/d') . '/';
                case 'filename_prefix':
                    $clean = preg_replace('/[^a-z0-9]/i', '', strtolower($filename));
                    if (strlen($clean) < 2) return 'misc/';
                    return substr($clean, 0, 1) . '/' . substr($clean, 1, 1) . '/';
                case 'hash_levels':
                    $hash = md5($filename);
                    return substr($hash, 0, 1) . '/' . substr($hash, 1, 1) . '/' . substr($hash, 2, 1) . '/';
                default:
                    return '';
            }
        }

        protected function thumbnailExists(string $disk, string $path): bool
        {
            try {
                return Storage::disk($disk)->exists($path);
            } catch (\Exception $e) {
                return false;
            }
        }
    }
