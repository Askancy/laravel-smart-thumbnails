<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;

class GenerationLock
{
    public static function name(string $disk, string $path): string
    {
        $path = (new \League\Flysystem\WhitespacePathNormalizer())->normalizePath($path);
        return 'smart_thumbnails:generate:' . hash('sha256', $disk . ':' . $path);
    }

    public static function run(string $disk, string $path, callable $callback)
    {
        $repository = Cache::store(config('thumbnails.lock_store'));
        $seconds = (int) config('thumbnails.generation_lock_seconds', 300);
        $wait = (int) config('thumbnails.generation_lock_wait', 10);
        if ($seconds < 1 || $wait < 0) {
            throw new \RuntimeException('Thumbnail locking requires a valid cache store, positive lock lifetime, and non-negative wait.');
        }
        $store = $repository->getStore();
        if (!$store instanceof LockProvider) {
            throw new \RuntimeException('The configured thumbnail cache store does not support atomic locks.');
        }
        $lock = $store->lock(self::name($disk, $path), $seconds);

        return $lock->block($wait, fn () => $callback($lock));
    }

    public static function assertOwned(Lock $lock): void
    {
        if (!method_exists($lock, 'isOwnedByCurrentProcess') || !$lock->isOwnedByCurrentProcess()) {
            throw new \RuntimeException('The thumbnail generation lock expired before publication.');
        }
    }
}
