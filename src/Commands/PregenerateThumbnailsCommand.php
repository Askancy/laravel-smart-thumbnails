<?php

namespace Askancy\LaravelSmartThumbnails\Commands;

use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PregenerateThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:pregenerate {preset? : Specific preset to pregenerate} {--disk=public : Source disk to scan} {--path= : Specific path to scan (default: images/)} {--async : Generate thumbnails asynchronously} {--force : Regenerate existing thumbnails} {--chunk=50 : Number of images to process in each chunk} {--dry-run : Show what would be generated without actually generating}';

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
        if ($chunkSize < 1) {
            $this->error('The chunk size must be at least 1.');
            return 1;
        }
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

                        $totalGenerated += $result['generated_count'];
                        $totalSkipped += $result['skipped_count'];
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
            $this->info('💡 Process queued thumbnails with: php artisan queue:work --queue=' . config('thumbnails.queue_name', 'thumbnails'));
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    protected function scanForImages(string $disk, string $path): array
    {
        try {
            $allowedExtensions = config('thumbnails.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif']);
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
            $mainThumbnailPath = $this->thumbnailService->path();

            if ($force || !config('thumbnails.cache_thumbnails', true) || !$this->thumbnailExists($presetConfig['destination']['disk'], $mainThumbnailPath)) {
                if (!$dryRun) {
                    if ($async) {
                        $this->dispatchThumbnailJob($imagePath, $sourceDisk, $presetName, $presetConfig, $mainThumbnailPath, null, $force);
                    } else {
                        $this->thumbnailService->url(null, $force);
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
                    $effectiveConfig = array_replace_recursive($presetConfig, $variantConfig);
                    $variantThumbnailPath = $this->thumbnailService->path($variantName);

                    if ($force || !config('thumbnails.cache_thumbnails', true) || !$this->thumbnailExists($effectiveConfig['destination']['disk'] ?? $presetConfig['destination']['disk'], $variantThumbnailPath)) {
                        if (!$dryRun) {
                            if ($async) {
                                $this->dispatchThumbnailJob($imagePath, $sourceDisk, $presetName, $effectiveConfig, $variantThumbnailPath, $variantName, $force);
                            } else {
                                $this->thumbnailService->url($variantName, $force);
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
            'skipped_count' => $skipped,
        ];
    }

    protected function dispatchThumbnailJob(
        string $imagePath,
        string $sourceDisk,
        string $presetName,
        array $config,
        string $thumbnailPath,
        ?string $variant = null,
        bool $force = false
    ): void {
        GenerateThumbnailJob::dispatch(
            $imagePath,
            $sourceDisk,
            $presetName,
            $config,
            $thumbnailPath,
            $variant, $force, true
        )->onQueue(config('thumbnails.queue_name', 'thumbnails'));
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
