<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Storage;

class ThumbnailPublisher
{
    /** Publish only a complete temporary object, leaving an existing output intact on write failure. */
    public static function publish(string $disk, string $path, string $binary, Lock $lock, ?array $record = null): void
    {
        GenerationLock::assertOwned($lock);
        ThumbnailGenerator::ensureDestinationDirectory($disk, dirname($path));
        $storage = Storage::disk($disk);
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            if (!$storage->put($temporary, $binary)) {
                throw new \RuntimeException("Could not write thumbnail: {$path}");
            }
            GenerationLock::assertOwned($lock);
            if (!$storage->move($temporary, $path)) {
                throw new \RuntimeException("Could not publish thumbnail: {$path}");
            }
            ThumbnailGenerator::setPublicVisibility($disk, $path);
            if ($record !== null) {
                ThumbnailManifest::write($disk, $path, $binary, $record);
            }
        } finally {
            if ($storage->exists($temporary)) {
                $storage->delete($temporary);
            }
        }
    }
}
