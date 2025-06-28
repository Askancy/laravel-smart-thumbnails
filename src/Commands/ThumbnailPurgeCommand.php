<?php

namespace Askancy\LaravelSmartThumbnails\Commands;

use Illuminate\Console\Command;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;

class ThumbnailPurgeCommand extends Command
{
    protected $signature = 'thumbnail:purge 
                           {preset? : Specific preset to purge (optional)}
                           {--confirm : Skip confirmation prompt}';

    protected $description = 'Purge generated thumbnails';

    protected $thumbnailService;

    public function __construct(ThumbnailService $thumbnailService)
    {
        parent::__construct();
        $this->thumbnailService = $thumbnailService;
    }

    public function handle()
    {
        $preset = $this->argument('preset');

        if ($preset) {
            $this->purgePreset($preset);
        } else {
            $this->purgeAll();
        }
    }

    protected function purgeAll()
    {
        if (!$this->option('confirm')) {
            if (!$this->confirm('This will delete ALL generated thumbnails. Are you sure?')) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        $this->info('Purging all thumbnails...');

        try {
            $purgedCount = $this->thumbnailService->purgeAll();
            $this->info("Successfully purged {$purgedCount} thumbnails.");
        } catch (\Exception $e) {
            $this->error('Error purging thumbnails: ' . $e->getMessage());
        }
    }

    protected function purgePreset(string $preset)
    {
        if (!$this->option('confirm')) {
            if (!$this->confirm("This will delete all thumbnails for preset '{$preset}'. Are you sure?")) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        $this->info("Purging thumbnails for preset '{$preset}'...");

        try {
            $purgedCount = $this->thumbnailService->purgePreset($preset);
            $this->info("Successfully purged {$purgedCount} thumbnails for preset '{$preset}'.");
        } catch (\Exception $e) {
            $this->error('Error purging preset: ' . $e->getMessage());
        }
    }
}
