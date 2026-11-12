<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Askancy\LaravelSmartThumbnails\Support\CacheHelper;
use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\SourceImage;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailIdentity;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Storage;

class ObsoleteThumbnailPruner
{
    public function prune(?string $preset, int $days, bool $dryRun, ?callable $report = null): array
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('Retention days must be non-negative.');
        }
        $counts = array_fill_keys(['examined', 'current', 'recent', 'modified', 'missing', 'obsolete', 'deleted', 'busy', 'errors'], 0);
        $index = Storage::disk(ThumbnailManifest::disk());
        $cutoff = now()->subDays($days)->getTimestamp();
        foreach ($index->allFiles(ThumbnailManifest::root()) as $file) {
            if (!str_ends_with($file, '.json')) {
                continue;
            }
            try {
                $record = ThumbnailManifest::read($file);
                if ($preset !== null && $record['preset'] !== $preset) {
                    continue;
                }
                $counts['examined']++;
                $result = GenerationLock::run($record['disk'], $record['path'], function (Lock $lock) use ($index, $file, $cutoff, $dryRun) {
                    if (!$index->exists($file)) {
                        return ['state' => 'missing', 'deleted' => false];
                    }
                    // A forced render may have refreshed the record while pruning waited for its lock.
                    $record = ThumbnailManifest::read($file);
                    if ($record['generated_at'] > $cutoff) {
                        return ['state' => 'recent', 'deleted' => false];
                    }
                    $disk = Storage::disk($record['disk']);
                    if (!$disk->exists($record['path'])) {
                        if (!$dryRun && !$index->delete($file)) {
                            throw new \RuntimeException('Could not remove the missing thumbnail record.');
                        }
                        return ['state' => 'missing', 'deleted' => false];
                    }
                    if ($this->isCurrent($record)) {
                        return ['state' => 'current', 'deleted' => false];
                    }
                    $stream = $disk->readStream($record['path']);
                    if (!is_resource($stream)) {
                        throw new \RuntimeException('Cannot verify the obsolete thumbnail.');
                    }
                    try {
                        $hash = hash_init('sha256');
                        hash_update_stream($hash, $stream);
                        $unchanged = hash_equals($record['output_hash'], hash_final($hash));
                    } finally {
                        fclose($stream);
                    }
                    // Out-of-band replacements are not owned by this cleanup operation.
                    if (!$unchanged) {
                        return ['state' => 'modified', 'deleted' => false];
                    }
                    GenerationLock::assertOwned($lock);
                    if (!$dryRun) {
                        if (!$disk->delete($record['path']) || !$index->delete($file)) {
                            throw new \RuntimeException('Could not delete the obsolete thumbnail and its record.');
                        }
                        CacheHelper::flush([$record['preset']]);
                    }
                    return ['state' => 'obsolete', 'deleted' => !$dryRun];
                });
                $counts[$result['state']]++;
                $counts['deleted'] += $result['deleted'] ? 1 : 0;
                if ($result['state'] === 'obsolete' && $report !== null) {
                    $report(($dryRun ? 'Would delete: ' : 'Deleted: ') . $record['disk'] . ':' . $record['path']);
                }
            } catch (LockTimeoutException $e) {
                $counts['busy']++;
            } catch (\Throwable $e) {
                $counts['errors']++;
                if ($report !== null) {
                    $report('Preserved record ' . $file . ': ' . $e->getMessage());
                }
            }
        }
        return $counts;
    }

    private function isCurrent(array $record): bool
    {
        $presets = config('thumbnails.presets', []);
        $preset = $presets[$record['preset']] ?? null;
        if ($preset === null) {
            return false;
        }
        if ($record['variant'] !== null) {
            if (!isset($preset['variants'][$record['variant']])) {
                return false;
            }
            $preset = array_replace_recursive($preset, $preset['variants'][$record['variant']]);
        }
        if (!Storage::disk($record['source_disk'])->exists($record['source'])) {
            return false;
        }
        $source = ThumbnailIdentity::usesContentHash($record['source_disk'], $preset)
            ? SourceImage::read($record['source'], $record['source_disk'], $preset) : ['hash' => ''];
        return hash_equals($record['identity'], ThumbnailIdentity::fingerprint(
            $record['source'],
            $record['source_disk'],
            $preset,
            $record['variant'],
            $source['hash']
        ));
    }
}
