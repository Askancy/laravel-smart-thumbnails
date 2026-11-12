<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class RemoteSourceCacheTest extends TestCase
{
    private FilesystemAdapter $source;
    private int $reads = 0;
    private int $bytes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        $this->source = Storage::fake('remote');
        config([
            'filesystems.disks.remote' => ['driver' => 'scoped', 'disk' => 'spaces', 'prefix' => 'originals'],
            'filesystems.disks.spaces' => ['driver' => 's3', 'bucket' => 'images'],
            'thumbnails.presets.test.format' => 'png',
            'thumbnails.presets.test.smart_crop_enabled' => false,
            'thumbnails.presets.test.variants.small' => ['smartcrop' => '50x50'],
        ]);
        $this->replace('#ff0000');
        $proxy = Mockery::mock($this->source);
        $proxy->shouldReceive('readStream')->andReturnUsing(function ($path) {
            $this->reads++;
            $this->bytes += $this->source->size($path);
            return $this->source->readStream($path);
        });
        $proxy->shouldReceive('get')->never();
        Storage::set('remote', $proxy);
    }

    private function replace(string $color): void
    {
        // Solid red and blue PNG fixtures have identical sizes.
        $this->source->put('image.png', ThumbnailGenerator::createImageManager()
            ->create(120, 80)->fill($color)->toPng()->toString());
    }

    private function service(): ThumbnailService
    {
        return (clone app(ThumbnailService::class))->set('test')->src('image.png', 'remote')->strict();
    }

    public static function stores(): array
    {
        return [['array'], ['file']];
    }

    #[DataProvider('stores')]
    public function test_warm_requests_and_variants_transfer_no_original_bytes(string $store): void
    {
        config(['cache.default' => $store]);
        Cache::flush();
        $main = $this->service()->url();
        $small = $this->service()->url('small');
        $this->service()->imageData();
        $this->service()->imageData('small');
        $before = [$this->reads, $this->bytes];
        for ($i = 0; $i < 3; $i++) {
            // Forget resolved services between requests; only persistent cache is shared.
            app()->forgetInstance(ThumbnailService::class);
            $this->assertSame($main, $this->service()->url());
            $this->assertSame($small, $this->service()->urlSafe('small'));
            $this->assertTrue($this->service()->exists());
            $this->assertSame($main, $this->service()->imageData()['url']);
            Blade::render('<x-smart-thumbnail src="image.png" disk="remote" preset="test" :variants="[\'small\']" />');
        }
        $this->assertSame($before, [$this->reads, $this->bytes]);
        Cache::flush();
    }

    private function pixel(?string $variant = null): string
    {
        return ThumbnailGenerator::createImageManager()->read(
            Storage::disk('local')->get($this->service()->path($variant))
        )->pickColor(10, 10)->toHex();
    }

    #[DataProvider('stores')]
    public function test_cache_flush_and_elapsed_time_never_download_existing_originals(string $store): void
    {
        config(['cache.default' => $store]);
        Cache::flush();
        $main = $this->service()->imageData();
        $small = $this->service()->imageData('small');
        $before = [$this->reads, $this->bytes];
        // A missing original proves that a hit does not even require source existence.
        $this->source->delete('image.png');
        foreach ([301, 3601, 86401, 30 * 86400] as $seconds) {
            $this->travel($seconds)->seconds();
            Cache::flush();
            app()->forgetInstance(ThumbnailService::class);
            $this->assertSame($main['url'], $this->service()->url());
            $this->assertSame($small['url'], $this->service()->urlSafe('small'));
            $this->assertTrue($this->service()->exists());
            $this->assertSame($main, $this->service()->imageData());
            $this->assertSame($small, $this->service()->imageData('small'));
            Blade::render('<x-smart-thumbnail src="image.png" disk="remote" preset="test" :variants="[\'small\']" />');
        }
        config(['thumbnails.cache_urls' => false, 'thumbnails.cache_image_data' => false]);
        $this->assertSame($main, $this->service()->imageData());
        $this->assertSame($before, [$this->reads, $this->bytes]);
        Cache::flush();
    }

    public function test_path_can_be_computed_without_an_original_or_cache(): void
    {
        $first = $this->service()->path();
        $this->source->delete('image.png');
        Cache::flush();
        $this->assertSame($first, $this->service()->path());
        $this->assertFalse($this->service()->exists());
        $this->assertSame(0, $this->reads);
    }

    public function test_invalidation_removes_all_managed_variants_but_preserves_other_sources(): void
    {
        $main = $this->service()->imageData();
        $this->service()->imageData('small');
        $this->source->put('other.png', $this->source->get('image.png'));
        $other = $this->service()->src('other.png', 'remote');
        $other->url();
        $otherPath = $other->path();
        $this->replace('#0000ff');
        Cache::flush();
        $this->assertSame($main, $this->service()->imageData());
        $this->assertSame('ff0000', $this->pixel());
        $before = $this->reads;
        $this->service()->invalidateSource();
        $this->assertSame($before, $this->reads);
        $this->assertFalse($this->service()->exists());
        $this->assertFalse($this->service()->exists('small'));
        $this->assertTrue(Storage::disk('local')->exists($otherPath));
        $this->assertSame($main['url'], $this->service()->url());
        $this->service()->url('small');
        $this->assertSame('0000ff', $this->pixel());
        $this->assertSame('0000ff', $this->pixel('small'));
    }

    public function test_force_and_fresh_metadata_replace_pixels_at_the_same_url(): void
    {
        $first = $this->service()->imageData();
        $this->replace('#0000ff');
        $this->assertSame($first, $this->service()->imageDataFresh());
        $this->assertSame('0000ff', $this->pixel());
        $this->replace('#00ff00');
        $this->assertSame($first['url'], $this->service()->regenerate());
        $this->assertSame('00ff00', $this->pixel());
        $this->assertSame(3, $this->reads);
    }

    public function test_missing_derivative_reads_the_current_original_and_repairs_it(): void
    {
        $first = $this->service()->url();
        $path = $this->service()->path();
        $this->replace('#0000ff');
        Storage::disk('local')->delete($path);
        $this->assertSame($first, $this->service()->url());
        $this->assertSame('0000ff', $this->pixel());
        $this->assertSame(2, $this->reads);
    }

    public function test_content_identity_remains_available_for_same_size_replacement_detection(): void
    {
        config(['thumbnails.source_identity' => 'content']);
        $first = $this->service()->url();
        $size = $this->source->size('image.png');
        $mtime = $this->source->lastModified('image.png');
        $this->replace('#0000ff');
        touch($this->source->path('image.png'), $mtime);
        $this->assertSame($size, $this->source->size('image.png'));
        $this->assertNotSame($first, $this->service()->url());
    }

    public function test_storage_render_and_explicit_version_changes_select_distinct_paths(): void
    {
        $first = $this->service()->path();
        config(['filesystems.disks.spaces.bucket' => 'other']);
        $second = $this->service()->path();
        config(['thumbnails.presets.test.quality' => 40]);
        $third = $this->service()->path();
        config(['thumbnails.presets.test.source_version' => 'revision-2']);
        $fourth = $this->service()->path();
        $this->assertCount(4, array_unique([$first, $second, $third, $fourth]));
        $this->assertSame(0, $this->reads);
    }

    public function test_generation_still_rejects_oversized_or_invalid_originals_and_retries(): void
    {
        config(['thumbnails.max_file_size' => 1, 'thumbnails.placeholder_url' => '/fallback.png']);
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        $this->assertFalse($this->service()->exists());
        config(['thumbnails.max_file_size' => 1000000]);
        $this->source->put('image.png', 'not an image');
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        $this->assertFalse($this->service()->exists());
        $this->replace('#ff0000');
        $this->assertNotSame('/fallback.png', $this->service()->url());
        $this->assertSame('ff0000', $this->pixel());
    }

    public function test_queue_skips_existing_derivative_without_reading_original_after_cache_flush(): void
    {
        $url = $this->service()->url();
        $this->source->delete('image.png');
        Cache::flush();
        $job = new \Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob(
            'image.png',
            'remote',
            'test',
            config('thumbnails.presets.test'),
            'unused.png',
            null,
            false,
            true
        );
        $job->handle(new \Askancy\LaravelSmartThumbnails\Services\SmartCropService());
        $this->assertSame($url, $this->service()->url());
        $this->assertSame(1, $this->reads);
    }

    public function test_invalidation_waits_for_generation_and_can_be_retried(): void
    {
        $this->service()->url();
        config(['thumbnails.generation_lock_wait' => 0]);
        \Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest::withSourceLock('image.png', 'remote', function () {
            try {
                $this->service()->invalidateSource();
                $this->fail('Invalidation must not race an active generation.');
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                $this->assertTrue($this->service()->exists());
            }
        });
        $this->service()->invalidateSource();
        $this->assertFalse($this->service()->exists());
    }

    public function test_date_based_directories_do_not_change_a_remote_path_overnight(): void
    {
        config(['thumbnails.presets.test.subdirectory_strategy' => 'date_based']);
        $first = $this->service()->url();
        $this->travel(2)->days();
        Cache::flush();
        $this->assertSame($first, $this->service()->url());
        $this->assertSame(1, $this->reads);
    }

    public function test_pruning_path_identities_does_not_download_originals(): void
    {
        $this->service()->url();
        $old = $this->service()->path();
        config(['thumbnails.presets.test.quality' => 40]);
        $this->service()->url();
        $before = $this->reads;
        $result = (new \Askancy\LaravelSmartThumbnails\Services\ObsoleteThumbnailPruner())->prune('test', 0, false);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['current']);
        $this->assertSame(0, $result['errors']);
        $this->assertFalse(Storage::disk('local')->exists($old));
        $this->assertSame($before, $this->reads);
    }
}
