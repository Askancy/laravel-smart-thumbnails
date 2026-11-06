<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Cache;

/** Tag-aware cache with versioned namespaces for stores without native tags. */
class CacheHelper
{
    public static function supportsTagging(): bool
    {
        $store = Cache::store();
        return method_exists($store, 'supportsTags') && $store->supportsTags();
    }

    private static function key(array $tags, string $key): string
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = Cache::get('smart_thumbnails:tag:' . hash('sha256', $tag), '0');
        }

        return 'smart_thumbnails:' . hash('sha256', serialize([$versions, $key]));
    }

    public static function remember(array $tags, string $key, int $ttl, callable $callback)
    {
        return self::supportsTagging() ? Cache::tags($tags)->remember($key, $ttl, $callback)
            : Cache::remember(self::key($tags, $key), $ttl, $callback);
    }

    public static function put(array $tags, string $key, $value, int $ttl): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->put($key, $value, $ttl);
        } else {
            Cache::put(self::key($tags, $key), $value, $ttl);
        }
    }

    public static function get(array $tags, string $key)
    {
        return self::supportsTagging() ? Cache::tags($tags)->get($key) : Cache::get(self::key($tags, $key));
    }

    public static function forget(array $tags, string $key): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->forget($key);
        } else {
            Cache::forget(self::key($tags, $key));
        }
    }

    public static function flush(array $tags): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->flush();
            return;
        }
        foreach ($tags as $tag) {
            Cache::forever('smart_thumbnails:tag:' . hash('sha256', $tag), bin2hex(random_bytes(16)));
        }
    }

    public static function has(array $tags, string $key): bool
    {
        return self::supportsTagging() ? Cache::tags($tags)->has($key) : Cache::has(self::key($tags, $key));
    }

    public static function getDriverInfo(): array
    {
        $supportsTagging = self::supportsTagging();

        return [
            'driver' => config('cache.default'), 'supports_tagging' => $supportsTagging,
            'recommendation' => $supportsTagging ? 'Native cache tags enabled.' : 'Versioned cache namespaces enabled.',
            'supported_drivers' => ['redis', 'memcached', 'array'],
        ];
    }
}
