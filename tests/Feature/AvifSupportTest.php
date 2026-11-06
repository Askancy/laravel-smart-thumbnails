<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Commands\PregenerateThumbnailsCommand;
use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Mockery;

class AvifSupportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        config(['thumbnails.presets.test.smart_crop_enabled' => false]);
    }

    public function test_avif_sources_are_allowed_without_an_explicit_allowlist(): void
    {
        ThumbnailGenerator::validateImageContent('source.avif');
        $this->removeAllowlist();
        ThumbnailGenerator::validateImageContent('source.AVIF');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("File extension 'avif' not allowed");
        config(['thumbnails.allowed_extensions' => ['jpg', 'png']]);
        ThumbnailGenerator::validateImageContent('source.avif');
    }

    public function test_unsupported_avif_encoding_reports_a_clear_exception(): void
    {
        $driver = Mockery::mock(DriverInterface::class);
        $driver->shouldReceive('supports')->once()->with('avif')->andReturn(false);
        $image = Mockery::mock(ImageInterface::class);
        $image->shouldReceive('driver')->once()->andReturn($driver);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AVIF encoding is not supported by the configured image driver');

        ThumbnailGenerator::convertFormat($image, 'AVIF', 75);
    }

    public function test_service_reads_avif_sources_and_generates_avif_variants(): void
    {
        $manager = $this->avifManager();
        config(['thumbnails.presets.test.variants.avif' => ['format' => 'avif', 'quality' => 75]]);
        Storage::disk('local')->put('source.AVIF', $manager->create(200, 150)->fill('#ff0000')->toAvif()->toString());

        $service = new ThumbnailService(new SmartCropService());
        $service->set('test')->src('source.AVIF', 'local')->strict()->url();
        $service->url('avif');

        $jpeg = Storage::disk('local')->get($this->thumbnailPath('source.AVIF'));
        $avif = Storage::disk('local')->get($this->thumbnailPath('source.AVIF', 'avif'));
        $this->assertSame('image/jpeg', getimagesizefromstring($jpeg)['mime']);
        $this->assertAvifThumbnail($avif);
    }

    public function test_queue_job_generates_avif_thumbnails(): void
    {
        $manager = $this->avifManager();
        Storage::disk('local')->put('source.avif', $manager->create(200, 150)->toAvif()->toString());
        $preset = config('thumbnails.presets.test');
        $preset['format'] = 'avif';

        $job = new GenerateThumbnailJob('source.avif', 'local', 'test', $preset, 'test-crops/source_100_100.avif');
        $job->handle(new SmartCropService());

        $this->assertAvifThumbnail(Storage::disk('local')->get('test-crops/source_100_100.avif'));
    }

    public function test_pregeneration_scans_avif_sources_with_the_default_allowlist(): void
    {
        $manager = $this->avifManager();
        $this->removeAllowlist();
        Storage::disk('local')->put('images/source.AVIF', $manager->create(200, 150)->toAvif()->toString());

        $command = new class(new ThumbnailService(new SmartCropService())) extends PregenerateThumbnailsCommand {
            public function scan(string $disk, string $path): array
            {
                return $this->scanForImages($disk, $path);
            }
        };

        $this->assertSame(['images/source.AVIF'], $command->scan('local', 'images/'));
        config(['thumbnails.allowed_extensions' => ['jpg']]);
        $this->assertSame([], $command->scan('local', 'images/'));
    }

    public function test_avif_thumbnails_are_included_in_distribution_and_purge(): void
    {
        Storage::disk('local')->put('test-crops/source_100_100.avif', 'thumbnail');
        Storage::disk('local')->put('test-crops/source_100_100_small.AVIF', 'variant');
        Storage::disk('local')->put('test-crops/original.avif', 'original');
        $service = new ThumbnailService(new SmartCropService());

        $distribution = $service->analyzeDistribution('test');
        $this->assertSame(2, $distribution['total_files']);
        $this->assertSame(2, $distribution['distribution_by_format']['avif']['count']);
        $this->assertSame(2, $service->purgePreset('test'));
        $this->assertSame(['test-crops/original.avif'], Storage::disk('local')->allFiles('test-crops'));
    }

    private function removeAllowlist(): void
    {
        $config = config('thumbnails');
        unset($config['allowed_extensions']);
        config(['thumbnails' => $config]);
    }

    private function avifManager(): \Intervention\Image\ImageManager
    {
        $manager = ThumbnailGenerator::createImageManager();
        if (!$manager->driver()->supports('avif')) {
            $this->markTestSkipped('The installed image driver does not support AVIF.');
        }

        return $manager;
    }

    private function assertAvifThumbnail(string $binary): void
    {
        $size = getimagesizefromstring($binary);
        $this->assertSame('image/avif', $size['mime']);
        $this->assertSame(100, $size[0]);
        $this->assertSame(100, $size[1]);
        $decoded = ThumbnailGenerator::createImageManager()->read($binary);
        $this->assertSame(100, $decoded->width());
        $this->assertSame(100, $decoded->height());
    }
}
