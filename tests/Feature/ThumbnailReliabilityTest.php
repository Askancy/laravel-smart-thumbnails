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

class ThumbnailReliabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Cache::flush();
        config(['thumbnails.presets.test.format' => 'png', 'thumbnails.presets.test.smart_crop_enabled' => false]);
        Storage::disk('local')->put('images/source.png', $this->png('#ff0000'));
    }

    private function png(string $color): string
    {
        return ThumbnailGenerator::createImageManager()->create(200, 100)->fill($color)->toPng()->toString();
    }

    private function service(string $path = 'images/source.png', string $disk = 'local'): ThumbnailService
    {
        return (new ThumbnailService(new SmartCropService()))->set('test')->src($path, $disk)->strict();
    }

    public function test_same_filename_and_identical_bytes_in_different_paths_or_disks_do_not_collide(): void
    {
        $binary = $this->png('#ff0000');
        Storage::disk('local')->put('a/photo.png', $binary);
        Storage::disk('local')->put('b/photo.png', $binary);
        Storage::disk('public')->put('a/photo.png', $binary);
        $urls = [$this->service('a/photo.png')->url(), $this->service('b/photo.png')->url(),
            $this->service('a/photo.png', 'public')->url()];
        $this->assertCount(3, array_unique($urls));
        $this->assertCount(3, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_replacement_with_identical_size_and_mtime_gets_a_new_url_and_new_pixels(): void
    {
        $red = $this->png('#ff0000');
        $blue = $this->png('#0000ff');
        $length = max(strlen($red), strlen($blue));
        Storage::disk('local')->put('images/source.png', str_pad($red, $length, "\0"));
        touch(Storage::disk('local')->path('images/source.png'), 1700000000);
        $service = $this->service();
        $before = $service->url();
        Storage::disk('local')->put('images/source.png', str_pad($blue, $length, "\0"));
        touch(Storage::disk('local')->path('images/source.png'), 1700000000);
        $after = $service->url();
        $this->assertNotSame($before, $after);
        $image = ThumbnailGenerator::createImageManager()->read(Storage::disk('local')->get($service->path()));
        $this->assertSame('0000ff', $image->pickColor(50, 50)->toHex());
    }

    public function test_quality_changes_get_a_distinct_file_and_url(): void
    {
        $service = $this->service();
        $before = $service->url();
        config(['thumbnails.presets.test.quality' => 40]);
        $after = $service->set('test')->url();
        $this->assertNotSame($before, $after);
        $this->assertCount(2, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_purge_and_external_deletion_cannot_leave_a_cached_url_to_a_missing_file(): void
    {
        foreach (['array', 'file'] as $cache) {
            config(['cache.default' => $cache]);
            Cache::put('other-application-data', 'keep', 60);
            $service = $this->service();
            $url = $service->url();
            $path = $service->path();
            $this->assertSame(1, $service->purgePreset('test'));
            Storage::disk('local')->assertMissing($path);
            $this->assertSame($url, $service->url());
            Storage::disk('local')->assertExists($path);
            Storage::disk('local')->delete($path);
            $service->url();
            Storage::disk('local')->assertExists($path);
            $this->assertSame('keep', Cache::get('other-application-data'));
        }
    }

    public function test_purge_includes_variant_destinations_on_other_disks(): void
    {
        config(['thumbnails.presets.test.variants.other' => ['destination' => ['disk' => 'public', 'path' => 'other-thumbs/']]]);
        $service = $this->service();
        $service->url();
        $service->url('other');
        $this->assertCount(1, Storage::disk('public')->allFiles('other-thumbs'));
        $this->assertSame(2, $service->purgePreset('test'));
        $this->assertSame([], Storage::disk('public')->allFiles('other-thumbs'));
    }

    public function test_force_regenerates_existing_files_in_synchronous_and_async_commands(): void
    {
        foreach ([false, true] as $async) {
            $service = $this->service();
            $service->url();
            $path = $service->path();
            Storage::disk('local')->put($path, 'corrupt-existing-thumbnail');
            if ($async) {
                Bus::fake([GenerateThumbnailJob::class]);
            }
            $options = ['preset' => 'test', '--disk' => 'local', '--force' => true];
            if ($async) {
                $options['--async'] = true;
            }
            $this->artisan('thumbnails:pregenerate', $options)->assertExitCode(0);
            if ($async) {
                Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 1);
                foreach (Bus::dispatched(GenerateThumbnailJob::class) as $job) {
                    $job->handle(new SmartCropService());
                }
            }
            $size = getimagesizefromstring(Storage::disk('local')->get($path));
            $this->assertSame(100, $size[0]);
            $this->assertSame(100, $size[1]);
        }
    }

    public function test_queued_source_replacement_uses_the_current_content_identity(): void
    {
        Bus::fake([GenerateThumbnailJob::class]);
        $oldPath = $this->service()->path();
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local', '--async' => true])->assertExitCode(0);
        Storage::disk('local')->put('images/source.png', $this->png('#0000ff'));
        foreach (Bus::dispatched(GenerateThumbnailJob::class) as $job) {
            $job->handle(new SmartCropService());
        }
        Storage::disk('local')->assertMissing($oldPath);
        $current = $this->service()->path();
        Storage::disk('local')->assertExists($current);
        $this->assertCount(1, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_validation_and_size_limits_apply_to_requests_and_jobs(): void
    {
        foreach (['mismatch', 'not-image', 'oversize'] as $case) {
            config(['thumbnails.max_file_size' => $case === 'oversize' ? 1 : 1024 * 1024]);
            $path = $case === 'mismatch' ? 'bad.jpg' : 'bad.png';
            Storage::disk('local')->put($path, $case === 'not-image' ? 'not an image' : $this->png('#ff0000'));
            foreach ([false, true] as $queued) {
                try {
                    if ($queued) {
                        (new GenerateThumbnailJob($path, 'local', 'test', config('thumbnails.presets.test'), 'test-crops/bad_100_100.png'))
                            ->handle(new SmartCropService());
                    } else {
                        $this->service($path)->url();
                    }
                    $this->fail("Invalid source {$case} was accepted.");
                } catch (\RuntimeException $e) {
                    $this->assertMatchesRegularExpression('/extension|supported image|max_file_size/', $e->getMessage());
                }
            }
            $this->assertSame([], Storage::disk('local')->allFiles('test-crops'));
        }
    }

    public function test_disabling_content_validation_does_not_disable_size_limits(): void
    {
        config(['thumbnails.validate_image_content' => false, 'thumbnails.max_file_size' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_file_size');
        $this->service()->url();
    }

    public function test_explicit_zero_size_limit_and_disabled_content_validation_are_respected(): void
    {
        config(['thumbnails.validate_image_content' => false, 'thumbnails.max_file_size' => 0]);
        Storage::disk('local')->put('source.unusual', $this->png('#ff0000'));
        $this->service('source.unusual')->url();
        $this->assertCount(1, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_no_upscale_uses_actual_smaller_dimensions_and_variant_override(): void
    {
        Storage::disk('local')->put('small.png', ThumbnailGenerator::createImageManager()->create(40, 20)->fill('#ff0000')->toPng()->toString());
        config(['thumbnails.allow_upscale' => false, 'thumbnails.presets.test.variants.enlarged' => ['allow_upscale' => true]]);
        $service = $this->service('small.png');
        foreach (['smart', 'center'] as $mode) {
            config(['thumbnails.presets.test.crop_mode' => $mode]);
            $small = $service->set('test')->imageData();
            $this->assertSame(20, $small['width']);
            $this->assertSame(20, $small['height']);
        }
        $large = $service->imageData('enlarged');
        $this->assertSame(100, $large['width']);
        $this->assertSame(100, $large['height']);
        config(['thumbnails.presets.test.crop_mode' => 'dont_crop', 'thumbnails.presets.test.dont_crop_background' => '#0000ff']);
        $service->set('test')->url();
        $result = ThumbnailGenerator::createImageManager()->read(Storage::disk('local')->get($service->path()));
        $this->assertSame(100, $result->width());
        $this->assertSame('0000ff', $result->pickColor(29, 50)->toHex());
        $this->assertSame('ff0000', $result->pickColor(30, 50)->toHex());
    }
}
