<?php

namespace Askancy\LaravelSmartThumbnails\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Askancy\LaravelSmartThumbnails\Providers\ThumbnailServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function thumbnailPath(string $source, ?string $variant = null, string $preset = 'test', string $disk = 'local'): string
    {
        return (new \Askancy\LaravelSmartThumbnails\Services\ThumbnailService(new \Askancy\LaravelSmartThumbnails\Services\SmartCropService()))
            ->set($preset)->src($source, $disk)->path($variant);
    }

    protected function getPackageProviders($app)
    {
        return [
            ThumbnailServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Thumbnail' => \Askancy\LaravelSmartThumbnails\Facades\Thumbnail::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('thumbnails.presets.test', [
            'format' => 'jpg',
            'smartcrop' => '100x100',
            'destination' => ['disk' => 'local', 'path' => 'test-crops/'],
            'quality' => 85,
            'subdirectory_strategy' => 'none',
        ]);

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ]);
    }
}
