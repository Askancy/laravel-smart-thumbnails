<?php

namespace Askancy\LaravelSmartThumbnails\Commands;

use Askancy\LaravelSmartThumbnails\Services\ObsoleteThumbnailPruner;
use Illuminate\Console\Command;

class PruneThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:prune {preset? : Limit cleanup to one preset} {--days= : Minimum age in days, defaults to obsolete_retention_days} {--dry-run : Preview obsolete files without deleting them}';
    protected $description = 'Remove tracked obsolete thumbnail versions after the retention period';

    public function handle(ObsoleteThumbnailPruner $pruner): int
    {
        $days = filter_var($this->option('days') ?? config('thumbnails.obsolete_retention_days', 30), FILTER_VALIDATE_INT);
        if ($days === false || $days < 0) {
            $this->error('Retention days must be a non-negative integer.');
            return 1;
        }
        try {
            $counts = $pruner->prune($this->argument('preset'), $days, (bool) $this->option('dry-run'), fn ($message) => $this->line($message));
            $this->table(['Result', 'Count'], array_map(fn ($key, $value) => [$key, $value], array_keys($counts), array_values($counts)));
            return $counts['errors'] > 0 ? 1 : 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
