<?php

namespace Askancy\LaravelSmartThumbnails\Commands;

use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\LegacyThumbnailIndex;
use Askancy\LaravelSmartThumbnails\Support\SourceImage;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailIdentity;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AdoptLegacyThumbnailsCommand extends Command
{
    protected $signature = 'thumbnails:adopt-legacy {manifests* : Explicit paths on the private manifest disk (maximum 100)}
        {--read-sources : Allow one validated source read per unadopted manifest}
        {--dry-run : Verify without writing pointers}';

    protected $description = 'Verify and persistently reuse compatible 4.3 thumbnail URLs without rendering';

    public function handle(): int
    {
        $files = array_unique($this->argument('manifests'));
        if (count($files) > 100) {
            $this->error('Supply at most 100 explicit manifest paths per batch.');
            return self::FAILURE;
        }
        $counts = array_fill_keys(['reused', 'missing', 'ambiguous', 'source_reads', 'source_bytes', 'errors'], 0);
        foreach ($files as $file) {
            try {
                $record = ThumbnailManifest::read($file);
                $state = ThumbnailManifest::withSourceLock($record['source'], $record['source_disk'], function ($sourceLock) use ($record, $file, &$counts) {
                    return GenerationLock::run($record['disk'], $record['path'], function ($lock) use ($record, $file, $sourceLock, &$counts) {
                        // Re-read after locking: invalidation/pruning may have removed this record.
                        $record = ThumbnailManifest::read($file);
                        $config = config('thumbnails.presets', [])[$record['preset']] ?? null;
                        if ($config === null || ($record['variant'] !== null && !isset($config['variants'][$record['variant']]))) {
                            return 'ambiguous';
                        }
                        if ($record['variant'] !== null) {
                            $config = array_replace_recursive($config, $config['variants'][$record['variant']]);
                        }
                        $config = ThumbnailIdentity::settings($config);
                        if (ThumbnailIdentity::usesContentHash($record['source_disk'], $config)
                            || $record['disk'] !== $config['destination']['disk']
                            || $record['destination_root'] !== trim($config['destination']['path'], '/')) {
                            return 'ambiguous';
                        }
                        $output = Storage::disk($record['disk']);
                        if (!$output->exists($record['path'])) {
                            return 'missing';
                        }
                        $adopted = LegacyThumbnailIndex::resolve($record['source'], $record['source_disk'], $config, $record['variant'], $record['preset']);
                        if ($adopted !== null) {
                            return $adopted['path'] === $record['path'] ? 'reused' : 'ambiguous';
                        }
                        if (!$this->option('read-sources')) {
                            return 'ambiguous';
                        }
                        if (!Storage::disk($record['source_disk'])->exists($record['source'])) {
                            return 'missing';
                        }
                        // The 4.3 manifest contains only a one-way identity, not the source hash.
                        $counts['source_reads']++;
                        $source = SourceImage::read($record['source'], $record['source_disk'], $config);
                        $counts['source_bytes'] += strlen($source['content']);
                        $legacy = $config;
                        $legacy['source_identity'] = 'content';
                        $identity = ThumbnailIdentity::fingerprint($record['source'], $record['source_disk'], $legacy, $record['variant'], $source['hash']);
                        if (!hash_equals($record['identity'], $identity)) {
                            return 'ambiguous';
                        }
                        $binary = $output->get($record['path']);
                        if (!is_string($binary) || !hash_equals($record['output_hash'], hash('sha256', $binary))) {
                            return 'ambiguous';
                        }
                        GenerationLock::assertOwned($sourceLock);
                        GenerationLock::assertOwned($lock);
                        if (!$this->option('dry-run')) {
                            LegacyThumbnailIndex::adopt($record, $config);
                        }
                        return 'reused';
                    });
                });
                $counts[$state]++;
                $this->line($state . ': ' . $file);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $this->error($file . ': ' . $e->getMessage());
            }
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
