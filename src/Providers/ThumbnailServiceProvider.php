<?php

namespace Askancy\LaravelSmartThumbnails\Providers;

use Illuminate\Support\ServiceProvider;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Commands\ThumbnailPurgeCommand;

class ThumbnailServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/thumbnails.php',
            'thumbnails'
        );

        $this->app->singleton(SmartCropService::class);

        $this->app->singleton('laravel-smart-thumbnails', function ($app) {
            return new ThumbnailService($app->make(SmartCropService::class));
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/thumbnails.php' => config_path('thumbnails.php'),
            ], 'laravel-smart-thumbnails-config');

            $this->commands([
                ThumbnailPurgeCommand::class,
            ]);
        }
    }
}
