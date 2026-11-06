<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class CropModesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        $image = ThumbnailGenerator::createImageManager()->create(300, 100)->fill('#000000');
        $bitmap = $image->core()->native();
        imagefilledrectangle($bitmap, 270, 25, 299, 74, imagecolorallocate($bitmap, 255, 0, 0));
        Storage::disk('local')->put('source.png', $image->toPng()->toString());
        config(['thumbnails.presets.test.format' => 'png']);
    }

    public function test_modes_produce_distinct_results_and_sync_matches_queue(): void
    {
        $manager = ThumbnailGenerator::createImageManager();
        foreach (['smart', 'center', 'dont_crop'] as $mode) {
            config(['thumbnails.presets.test.crop_mode' => $mode, 'thumbnails.presets.test.dont_crop_background' => '#0000ff']);
            $preset = config('thumbnails.presets.test');
            $service = new ThumbnailService(new SmartCropService());
            $service->set('test')->src('source.png', 'local')->strict()->url();
            $path = $this->thumbnailPath('source.png');
            Storage::disk('local')->assertExists($path);
            $result = $manager->read(Storage::disk('local')->get($path));
            if ($mode === 'dont_crop') {
                $this->assertSame('0000ff', $result->pickColor(0, 0)->toHex());
                $this->assertSame('ff0000', $result->pickColor(98, 49)->toHex());
            } elseif ($mode === 'center') {
                $this->assertSame('000000', $result->pickColor(98, 49)->toHex());
            } else {
                $this->assertSame('ff0000', $result->pickColor(98, 49)->toHex());
            }
            $job = new GenerateThumbnailJob('source.png', 'local', 'test', $preset, "queue/{$mode}.png");
            $job->handle(new SmartCropService());
            $this->assertSame(Storage::disk('local')->get($path), Storage::disk('local')->get("queue/{$mode}.png"));
        }
        $this->assertCount(3, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_variant_can_preserve_the_image_while_main_uses_smart_crop(): void
    {
        config(['thumbnails.presets.test.crop_mode' => 'smart', 'thumbnails.presets.test.variants.full' => [
            'crop_mode' => 'dont_crop', 'dont_crop_background' => '#ffffff',
        ]]);
        $service = new ThumbnailService(new SmartCropService());
        $service->set('test')->src('source.png', 'local')->strict()->url();
        $service->url('full');
        $files = Storage::disk('local')->allFiles('test-crops');
        $this->assertCount(2, $files);
        $this->assertCount(1, array_filter($files, fn ($path) => str_contains($path, '_full_dont_crop_')));
        $this->assertSame(2, $service->analyzeDistribution('test')['total_files']);
        $this->assertSame(2, $service->purgePreset('test'));
    }

    public function test_background_changes_do_not_reuse_an_old_thumbnail_or_cached_url(): void
    {
        config(['thumbnails.presets.test.crop_mode' => 'dont_crop']);
        $urls = [];
        foreach (['#ffffff', '#0000ff'] as $background) {
            config(['thumbnails.presets.test.dont_crop_background' => $background]);
            $service = new ThumbnailService(new SmartCropService());
            $urls[] = $service->set('test')->src('source.png', 'local')->strict()->url();
        }
        $this->assertNotSame($urls[0], $urls[1]);
        $this->assertCount(2, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_legacy_disable_flag_still_selects_center_crop(): void
    {
        config(['thumbnails.presets.test.smart_crop_enabled' => false]);
        $service = new ThumbnailService(new SmartCropService());
        $service->set('test')->src('source.png', 'local')->strict()->url();
        $result = ThumbnailGenerator::createImageManager()->read(Storage::disk('local')->get($this->thumbnailPath('source.png')));
        $this->assertSame('000000', $result->pickColor(98, 49)->toHex());
    }

    public function test_unknown_mode_is_rejected_in_strict_mode(): void
    {
        config(['thumbnails.presets.test.crop_mode' => 'typo']);
        $service = new ThumbnailService(new SmartCropService());
        $this->expectException(\InvalidArgumentException::class);
        $service->set('test')->src('source.png', 'local')->strict()->url();
    }

    public function test_pregeneration_uses_the_same_paths_and_skips_existing_thumbnails(): void
    {
        config(['thumbnails.presets.test.crop_mode' => 'dont_crop']);
        $preset = config('thumbnails.presets.test');
        Storage::disk('local')->put('images/source.png', Storage::disk('local')->get('source.png'));
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local'])->assertExitCode(0);
        $path = $this->thumbnailPath('images/source.png');
        Storage::disk('local')->assertExists($path);
        $before = Storage::disk('local')->get($path);
        // A pre-existing file must be left alone by a subsequent pre-generation pass.
        Storage::disk('local')->put($path, $before . 'existing-marker');
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local'])->assertExitCode(0);
        $this->assertSame($before . 'existing-marker', Storage::disk('local')->get($path));
    }

    public function test_async_pregeneration_dispatches_dont_crop_variants_with_matching_paths(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);
        config(['thumbnails.presets.test.crop_mode' => 'smart', 'thumbnails.presets.test.variants.full' => [
            'crop_mode' => 'dont_crop', 'dont_crop_background' => '#ffffff',
        ]]);
        Storage::disk('local')->put('images/source.png', Storage::disk('local')->get('source.png'));
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local', '--async' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 2);
        // Execute both dispatched jobs, then synchronous calls must find exactly those files.
        foreach (Bus::dispatched(GenerateThumbnailJob::class) as $job) {
            $job->handle(new SmartCropService());
        }
        $before = Storage::disk('local')->allFiles('test-crops');
        $this->assertCount(2, $before);
        $service = new ThumbnailService(new SmartCropService());
        $service->set('test')->src('images/source.png', 'local')->strict()->url();
        $service->url('full');
        $this->assertSame($before, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_large_sources_use_content_analysis_in_queue_jobs_too(): void
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(1800, 600)->fill('#000000');
        $bitmap = $image->core()->native();
        imagefilledrectangle($bitmap, 1620, 150, 1799, 449, imagecolorallocate($bitmap, 255, 0, 0));
        Storage::disk('local')->put('large.png', $image->toPng()->toString());
        $preset = config('thumbnails.presets.test');
        $job = new GenerateThumbnailJob('large.png', 'local', 'test', $preset, 'queue/large.png');
        $job->handle(new SmartCropService());
        $result = $manager->read(Storage::disk('local')->get('queue/large.png'));
        $this->assertSame('ff0000', $result->pickColor(98, 49)->toHex());
    }

    public function test_imagick_can_render_smart_and_dont_crop_modes(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not installed.');
        }
        $manager = new \Intervention\Image\ImageManager(new \Intervention\Image\Drivers\Imagick\Driver());
        foreach (['smart', 'dont_crop'] as $mode) {
            $image = $manager->read(Storage::disk('local')->get('source.png'));
            $result = ThumbnailGenerator::transform($image, 100, 100, ['crop_mode' => $mode], new SmartCropService());
            $this->assertSame(100, $result->width());
            $this->assertSame(100, $result->height());
            $this->assertSame('ff0000', $result->pickColor(98, 49)->toHex());
        }
    }
}
