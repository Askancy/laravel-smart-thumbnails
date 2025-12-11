<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache Helper
 * Provides cache tagging support with automatic fallback for drivers that don't support tags
 */
class CacheHelper
{
    /**
     * Check if the current cache driver supports tagging
     *
     * @return bool
     */
    public static function supportsTagging(): bool
    {
        $driver = config('cache.default');
        $store = Cache::store($driver);

        // Drivers that support tagging
        $supportedDrivers = ['redis', 'memcached', 'array'];

        try {
            // Try to get the driver name from the store
            $method = new \ReflectionMethod($store, 'getStore');
            $method->setAccessible(true);
            $actualStore = $method->invoke($store);

            $driverClass = get_class($actualStore);

            // Check if it's a supported driver
            foreach ($supportedDrivers as $supported) {
                if (stripos($driverClass, $supported) !== false) {
                    return true;
                }
            }

            return false;
        } catch (\Exception $e) {
            // If we can't determine, try to use tags and catch exception
            try {
                Cache::tags(['test'])->get('test');
                return true;
            } catch (\Exception $tagException) {
                return false;
            }
        }
    }

    /**
     * Remember a value in cache with optional tagging support
     * Automatically falls back to non-tagged cache if driver doesn't support tags
     *
     * @param array $tags Cache tags (ignored if driver doesn't support tagging)
     * @param string $key Cache key
     * @param int $ttl Time to live in seconds
     * @param callable $callback Callback to generate value
     * @return mixed
     */
    public static function remember(array $tags, string $key, int $ttl, callable $callback)
    {
        if (self::supportsTagging()) {
            return Cache::tags($tags)->remember($key, $ttl, $callback);
        }

        // Fallback: use non-tagged cache
        // Prefix key with first tag to avoid collisions
        $prefixedKey = $tags[0] . ':' . $key;
        return Cache::remember($prefixedKey, $ttl, $callback);
    }

    /**
     * Put a value in cache with optional tagging support
     *
     * @param array $tags Cache tags
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return void
     */
    public static function put(array $tags, string $key, $value, int $ttl): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->put($key, $value, $ttl);
        } else {
            // Fallback: use non-tagged cache with prefix
            $prefixedKey = $tags[0] . ':' . $key;
            Cache::put($prefixedKey, $value, $ttl);
        }
    }

    /**
     * Get a value from cache with optional tagging support
     *
     * @param array $tags Cache tags
     * @param string $key Cache key
     * @return mixed
     */
    public static function get(array $tags, string $key)
    {
        if (self::supportsTagging()) {
            return Cache::tags($tags)->get($key);
        }

        // Fallback: use non-tagged cache with prefix
        $prefixedKey = $tags[0] . ':' . $key;
        return Cache::get($prefixedKey);
    }

    /**
     * Forget a value from cache with optional tagging support
     *
     * @param array $tags Cache tags
     * @param string $key Cache key
     * @return void
     */
    public static function forget(array $tags, string $key): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->forget($key);
        } else {
            // Fallback: use non-tagged cache with prefix
            $prefixedKey = $tags[0] . ':' . $key;
            Cache::forget($prefixedKey);
        }
    }

    /**
     * Flush all cache entries with specific tags
     * Falls back to flushing prefixed keys if tagging not supported
     *
     * @param array $tags Cache tags
     * @return void
     */
    public static function flush(array $tags): void
    {
        if (self::supportsTagging()) {
            Cache::tags($tags)->flush();
        } else {
            // Fallback: Log warning and do nothing
            // We can't flush by prefix with file/database drivers without iterating all keys
            Log::warning('Cache flush requested but current driver does not support tagging', [
                'tags' => $tags,
                'driver' => config('cache.default'),
                'recommendation' => 'Consider using Redis or Memcached for better cache performance with thumbnails'
            ]);

            // Optional: Clear entire cache if explicitly requested
            if (in_array('thumbnails', $tags) && config('thumbnails.allow_full_cache_clear', false)) {
                Log::warning('Clearing entire application cache due to thumbnail purge', [
                    'driver' => config('cache.default')
                ]);
                Cache::flush();
            }
        }
    }

    /**
     * Check if a key exists in cache
     *
     * @param array $tags Cache tags
     * @param string $key Cache key
     * @return bool
     */
    public static function has(array $tags, string $key): bool
    {
        if (self::supportsTagging()) {
            return Cache::tags($tags)->has($key);
        }

        // Fallback: use non-tagged cache with prefix
        $prefixedKey = $tags[0] . ':' . $key;
        return Cache::has($prefixedKey);
    }

    /**
     * Get cache driver information for debugging
     *
     * @return array
     */
    public static function getDriverInfo(): array
    {
        $driver = config('cache.default');
        $supportsTagging = self::supportsTagging();

        return [
            'driver' => $driver,
            'supports_tagging' => $supportsTagging,
            'recommendation' => $supportsTagging
                ? 'Current cache driver supports tagging - optimal performance'
                : 'Current cache driver does not support tagging. Consider Redis or Memcached for better performance.',
            'supported_drivers' => ['redis', 'memcached', 'array'],
        ];
    }
}
