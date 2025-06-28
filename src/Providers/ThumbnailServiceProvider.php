<?php

namespace Askancy\LaravelSmartThumbnails\Providers;

use Illuminate\Support\ServiceProvider;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Commands\ThumbnailPurgeCommand;

class ThumbnailServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/thumbnails.php',
            'thumbnails'
        );

        $this->app->singleton(SmartCropService::class);

        $this->app->singleton('laravel-smart-thumbnails', function ($app) {
            return new ThumbnailService($app->make(SmartCropService::class));
        });

        // Alias per la facade
        $this->app->alias('laravel-smart-thumbnails', ThumbnailService::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/thumbnails.php' => config_path('thumbnails.php'),
            ], 'laravel-smart-thumbnails-config');

            if (class_exists(ThumbnailPurgeCommand::class)) {
                $this->commands([
                    ThumbnailPurgeCommand::class,
                ]);
            }
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return ['laravel-smart-thumbnails', ThumbnailService::class];
    }
}
