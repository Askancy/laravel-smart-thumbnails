<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\ObsoleteThumbnailPruner;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\SourceImage;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class LegacyImageFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config(['thumbnails.presets.test.format' => 'png', 'thumbnails.presets.test.smart_crop_enabled' => false,
            'thumbnails.placeholder_url' => '/fallback.png']);
    }

    private function binary(string $format = 'png', string $color = '#e63946', int $width = 400, int $height = 225): string
    {
        $manager = ThumbnailGenerator::createImageManager();
        if (!$manager->driver()->supports($format)) {
            $this->markTestSkipped("The installed driver does not support {$format}.");
        }
        return ThumbnailGenerator::convertFormat($manager->create($width, $height)->fill($color), $format);
    }

    private function service(string $path = 'legacy.jpg'): ThumbnailService
    {
        return (new ThumbnailService(new SmartCropService()))->set('test')->src($path, 'local')->strict();
    }

    public static function legacyFormats(): array
    {
        return [['png'], ['webp'], ['avif']];
    }

    #[DataProvider('legacyFormats')]
    public function test_legacy_names_are_rejected_by_default_and_decoded_from_bytes_when_opted_in(string $format): void
    {
        $binary = $this->binary($format);
        Storage::disk('local')->put('legacy.jpg', $binary);
        foreach (['url', 'imageData', 'imageDataFresh', 'path', 'regenerate'] as $method) {
            try {
                $this->service()->{$method}();
                $this->fail("Strict {$method} must reject a {$format} named .jpg.");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('does not match file extension', $e->getMessage());
            }
        }
        $this->assertFalse($this->service()->exists());
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true]);
        $service = $this->service();
        $path = $service->path();
        $this->assertFalse($service->exists());
        $url = $service->url();
        $this->assertTrue($service->exists());
        $this->assertSame($url, $service->urlSafe());
        $this->assertSame($url, $service->regenerate());
        $this->assertSame($path, $service->path());
        $data = $service->imageData();
        $this->assertSame($data, $service->imageDataFresh());
        $this->assertSame(100, $data['width']);
        $this->assertSame(100, $data['height']);
        $this->assertSame('image/png', getimagesizefromstring(Storage::disk('local')->get($path))['mime']);
        $this->assertSame($binary, Storage::disk('local')->get('legacy.jpg'));
        $this->assertSame(hash('sha256', $binary), SourceImage::read('legacy.jpg', 'local', ['allow_mismatched_extensions' => true])['hash']);
        $record = ThumbnailManifest::read(ThumbnailManifest::path('local', $path));
        $this->assertSame('legacy.jpg', $record['source']);
        $this->assertSame($url, $data['url']);
    }

    public function test_allowlist_checks_the_actual_format_and_not_the_historical_suffix(): void
    {
        Storage::disk('local')->put('legacy.jpg', $this->binary());
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true, 'thumbnails.allowed_extensions' => ['jpg']]);
        try {
            $this->service()->url();
            $this->fail('A permitted .jpg suffix must not allow PNG bytes when PNG is disallowed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("Image format 'image/png' not allowed", $e->getMessage());
        }
        config(['thumbnails.allowed_extensions' => ['png']]);
        $this->service()->url();
        $this->assertTrue($this->service()->exists());
    }

    public function test_preset_and_variant_overrides_keep_other_uses_strict(): void
    {
        Storage::disk('local')->put('legacy.jpg', $this->binary());
        config(['thumbnails.allow_mismatched_extensions' => true]);
        $this->service()->url();
        config(['thumbnails.presets.test.allow_mismatched_extensions' => false,
            'thumbnails.presets.test.variants.archive' => ['smartcrop' => '50x50', 'allow_mismatched_extensions' => true]]);
        $this->assertFalse($this->service()->exists());
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        $data = $this->service()->imageData('archive');
        $this->assertSame(50, $data['width']);
        $this->assertTrue($this->service()->exists('archive'));
        $html = Blade::render('<x-smart-thumbnail src="legacy.jpg" disk="local" preset="test" variant="archive" :variants="[]" />');
        $this->assertStringContainsString('width="50"', $html);
        $this->assertStringNotContainsString('/fallback.png', $html);
    }

    public static function rejectedSources(): array
    {
        return [['text'], ['format'], ['bytes'], ['pixels']];
    }

    #[DataProvider('rejectedSources')]
    public function test_compatible_mode_never_bypasses_format_content_byte_or_pixel_checks(string $case): void
    {
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true]);
        Storage::disk('local')->put('legacy.jpg', $case === 'text' ? 'not an image' : $this->binary());
        if ($case === 'format') {
            config(['thumbnails.allowed_extensions' => ['jpeg']]);
        } elseif ($case === 'bytes') {
            config(['thumbnails.max_file_size' => 1]);
        } elseif ($case === 'pixels') {
            config(['thumbnails.max_megapixels' => 0.001]);
        }
        foreach (['url', 'imageData', 'imageDataFresh', 'path', 'regenerate'] as $method) {
            try {
                $this->service()->{$method}();
                $this->fail("Compatible {$method} accepted {$case}.");
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/not a supported image|format .*not allowed|max_file_size|too large/', $e->getMessage());
            }
        }
        $this->assertFalse($this->service()->exists());
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        try {
            (new GenerateThumbnailJob('legacy.jpg', 'local', 'test', config('thumbnails.presets.test'), 'test-crops/rejected_100_100.png'))
                ->handle(new SmartCropService());
            $this->fail("The worker accepted {$case}.");
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/not a supported image|format .*not allowed|max_file_size|too large/', $e->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_compatible_mode_still_validates_content_when_the_legacy_validation_switch_is_disabled(): void
    {
        config(['thumbnails.validate_image_content' => false, 'thumbnails.presets.test.allow_mismatched_extensions' => true]);
        Storage::disk('local')->put('legacy.jpg', 'not an image');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a supported image');
        $this->service()->url();
    }

    public function test_policy_changes_do_not_change_derivative_identity_or_bypass_strict_metadata_checks(): void
    {
        Storage::disk('local')->put('legacy.jpg', $this->binary('jpg'));
        $strict = $this->service()->imageData();
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true]);
        $compatible = $this->service()->imageData();
        $this->assertSame($strict, $compatible);
        $this->assertCount(1, Storage::disk('local')->allFiles('test-crops'));
        Storage::disk('local')->put('legacy.jpg', $this->binary('png'));
        $first = $this->service()->imageDataFresh();
        Storage::disk('local')->put('legacy.jpg', $this->binary('webp', '#0000ff'));
        $this->assertSame($first, $this->service()->imageData()); // Documented bounded metadata freshness.
        $updated = $this->service()->imageDataFresh();
        $this->assertNotSame($first['url'], $updated['url']);
        $this->assertSame($updated, $this->service()->imageData());
        config(['thumbnails.presets.test.allow_mismatched_extensions' => false]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match file extension');
        $this->service()->imageData();
    }

    public function test_sync_and_async_pregeneration_use_the_same_policy_and_managed_path(): void
    {
        config(['thumbnails.allowed_extensions' => ['png'], 'thumbnails.presets.test.allow_mismatched_extensions' => true]);
        $binary = $this->binary();
        Storage::disk('local')->put('images/legacy.jpg', $binary);
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local'])->assertExitCode(0);
        $service = $this->service('images/legacy.jpg');
        $path = $service->path();
        $this->assertTrue($service->exists());
        $service->purgePreset('test');
        Bus::fake([GenerateThumbnailJob::class]);
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local', '--async' => true])->assertExitCode(0);
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 1);
        foreach (Bus::dispatched(GenerateThumbnailJob::class) as $job) {
            // The queue payload carries the selected policy even if the live preset changes afterwards.
            config(['thumbnails.presets.test.allow_mismatched_extensions' => false]);
            $job->handle(new SmartCropService());
        }
        Storage::disk('local')->assertExists($path);
        $this->assertSame($binary, Storage::disk('local')->get('images/legacy.jpg'));
    }

    public function test_a_rejected_main_does_not_prevent_an_opted_in_variant_from_being_queued(): void
    {
        config(['thumbnails.allowed_extensions' => ['png'], 'thumbnails.presets.test.allow_mismatched_extensions' => false,
            'thumbnails.presets.test.variants.archive' => ['smartcrop' => '50x50', 'allow_mismatched_extensions' => true]]);
        Storage::disk('local')->put('images/legacy.jpg', $this->binary());
        Bus::fake([GenerateThumbnailJob::class]);
        $this->artisan('thumbnails:pregenerate', ['preset' => 'test', '--disk' => 'local', '--async' => true])->assertExitCode(1);
        Bus::assertDispatchedTimes(GenerateThumbnailJob::class, 1);
        foreach (Bus::dispatched(GenerateThumbnailJob::class) as $job) {
            $job->handle(new SmartCropService());
        }
        $this->assertTrue($this->service('images/legacy.jpg')->exists('archive'));
        $this->assertFalse($this->service('images/legacy.jpg')->exists());
    }

    public function test_pruning_reads_legacy_sources_using_their_current_preset_policy(): void
    {
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true]);
        Storage::disk('local')->put('legacy.jpg', $this->binary());
        $this->travel(-40)->days();
        try {
            $service = $this->service();
            $service->url();
            $old = $service->path();
        } finally {
            $this->travelBack();
        }
        $current = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $current['current']);
        $this->assertSame(0, $current['errors']);
        Storage::disk('local')->put('legacy.jpg', $this->binary('png', '#0000ff'));
        $obsolete = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $obsolete['deleted']);
        $this->assertSame(0, $obsolete['errors']);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists('legacy.jpg');
    }

    public function test_legacy_png_generates_webp_hero_and_gallery_dimensions(): void
    {
        $manager = ThumbnailGenerator::createImageManager();
        if (!$manager->driver()->supports('webp')) {
            $this->markTestSkipped('WebP codec unavailable.');
        }
        config(['thumbnails.presets.test.allow_mismatched_extensions' => true,
            'thumbnails.presets.test.format' => 'webp', 'thumbnails.presets.test.variants' => [
                'detail_hero' => ['smartcrop' => '1200x525'], 'gallery_preview' => ['smartcrop' => '500x300'],
            ]]);
        Storage::disk('local')->put('legacy.jpg', $this->binary('png', '#e63946', 3840, 2160));
        $hero = $this->service()->imageData('detail_hero');
        $preview = $this->service()->imageData('gallery_preview');
        $this->assertSame([1200, 525], [$hero['width'], $hero['height']]);
        $this->assertSame([500, 300], [$preview['width'], $preview['height']]);
        $this->assertStringContainsString('.webp', $hero['url']);
        $this->assertStringContainsString('.webp', $preview['url']);
    }
}
