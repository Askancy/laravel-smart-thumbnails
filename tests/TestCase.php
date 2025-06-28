<?php

namespace Askancy\LaravelSmartThumbnails\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Askancy\LaravelSmartThumbnails\Providers\ThumbnailServiceProvider;

abstract class TestCase extends BaseTestCase
{
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
        ]);

        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ]);
    }
}
