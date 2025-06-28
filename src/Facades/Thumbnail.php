<?php

namespace Askancy\LaravelSmartThumbnails\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Askancy\LaravelSmartThumbnails\Services\ThumbnailService set(string $configKey)
 * @method static \Askancy\LaravelSmartThumbnails\Services\ThumbnailService src(string $imagePath, string $sourceDisk = 'public')
 * @method static \Askancy\LaravelSmartThumbnails\Services\ThumbnailService silent()
 * @method static \Askancy\LaravelSmartThumbnails\Services\ThumbnailService strict()
 * @method static string url(string $variant = null)
 * @method static string urlSafe(string $variant = null)
 * @method static array getAvailableDisks()
 * @method static array getScopedDisks()
 * @method static array testDisk(string $disk)
 * @method static array getVariants(string $configKey = null)
 * @method static int purgeAll()
 * @method static int purgePreset(string $preset)
 */
class Thumbnail extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'laravel-smart-thumbnails';
    }
}
