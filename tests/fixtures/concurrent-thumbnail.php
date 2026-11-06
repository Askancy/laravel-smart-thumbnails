<?php

// Independent web/queue processes share a real filesystem and file-cache locks.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Intervention\Image\Interfaces\ImageInterface;

[$script, $root, $role] = $argv;
$application = new Application(dirname(__DIR__, 2));
$thumbnails = require dirname(__DIR__, 2) . '/src/config/thumbnails.php';
$thumbnails['cache_urls'] = false;
$thumbnails['memory_limit'] = null;
$thumbnails['generation_lock_wait'] = 10;
$thumbnails['presets'] = ['test' => [
    'format' => 'png', 'smartcrop' => '50x50', 'smart_crop_enabled' => true,
    'destination' => ['disk' => 'local', 'path' => 'outputs/'], 'subdirectory_strategy' => 'none',
]];
$application->instance('config', new Repository([
    'cache' => ['default' => 'file', 'prefix' => 'concurrency-test', 'stores' => [
        'file' => ['driver' => 'file', 'path' => $root . '/cache'],
    ]],
    'filesystems' => ['default' => 'local', 'disks' => [
        'local' => ['driver' => 'local', 'root' => $root . '/storage', 'url' => '/storage', 'throw' => true],
    ]],
    'logging' => ['default' => 'stderr', 'channels' => ['stderr' => ['driver' => 'errorlog']]],
    'thumbnails' => $thumbnails,
]));
$application->register(\Illuminate\Cache\CacheServiceProvider::class);
$application->register(\Illuminate\Filesystem\FilesystemServiceProvider::class);
Facade::setFacadeApplication($application);
$crop = new class ($root) extends SmartCropService {
    public function __construct(private string $root)
    {
    }

    public function smartCrop(ImageInterface $image, int $targetWidth, int $targetHeight): ImageInterface
    {
        file_put_contents($this->root . '/render-count', "render\n", FILE_APPEND | LOCK_EX);
        usleep(800000);
        return parent::smartCrop($image, $targetWidth, $targetHeight);
    }
};
file_put_contents($root . '/ready-' . $role, 'ready');
$deadline = microtime(true) + 10;
while (!file_exists($root . '/start')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent test start barrier timed out.');
    }
    usleep(10000);
}
if ($role === 'web') {
    (new ThumbnailService($crop))->set('test')->src('source.png', 'local')->strict()->url();
} else {
    (new GenerateThumbnailJob('source.png', 'local', 'test', $thumbnails['presets']['test'], 'unused.png', null, false, true))->handle($crop);
}
