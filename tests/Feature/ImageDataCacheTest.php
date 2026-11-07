<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\CacheHelper;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageDataCacheTest extends TestCase
{
    private FilesystemAdapter $source;
    private FilesystemAdapter $output;
    private object $reads;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');
        config(['filesystems.disks.source' => ['driver' => 'local', 'url' => 'https://source.example.test'],
            'filesystems.disks.output' => ['driver' => 'local', 'url' => 'https://cdn.example.test'],
            'thumbnails.presets.test.destination' => ['disk' => 'output', 'path' => 'thumbnails/'],
            'thumbnails.presets.test.format' => 'png', 'thumbnails.presets.test.smart_crop_enabled' => false]);
        $this->source = Storage::fake('source');
        $this->output = Storage::fake('output');
        $this->source->put('image.png', $this->png('#ff0000'));
        $this->reads = (object) ['source' => 0, 'output' => 0, 'source_exists' => 0, 'output_exists' => 0];
        $this->countReads('source', $this->source);
        $this->countReads('output', $this->output);
    }

    private function png(string $color, int $width = 200, int $height = 100): string
    {
        return ThumbnailGenerator::createImageManager()->create($width, $height)->fill($color)->toPng()->toString();
    }

    private function countReads(string $name, FilesystemAdapter $adapter): void
    {
        $proxy = Mockery::mock($adapter);
        $proxy->shouldReceive('exists')->andReturnUsing(function ($path) use ($name, $adapter) {
            $this->reads->{$name . '_exists'}++;
            return $adapter->exists($path);
        });
        $method = $name === 'source' ? 'readStream' : 'get';
        $proxy->shouldReceive($method)->andReturnUsing(function ($path) use ($name, $adapter, $method) {
            $this->reads->{$name}++;
            return $adapter->{$method}($path);
        });
        Storage::set($name, $proxy);
    }

    private function service(string $path = 'image.png', string $disk = 'source', string $preset = 'test'): ThumbnailService
    {
        return (clone app(ThumbnailService::class))->set($preset)->src($path, $disk)->strict();
    }

    public function test_cloned_services_reuse_actual_metadata_without_source_or_output_io(): void
    {
        $this->service()->url(); // Existing derivative and a warm URL cache, but no cached imageData yet.
        $this->reads->source = 0;
        $first = $this->service()->imageData();
        $before = clone $this->reads;
        $second = $this->service()->imageData();
        $this->assertSame($first, $second);
        $this->assertSame(1, $this->reads->source);
        $this->assertSame(1, $this->reads->output);
        $this->assertEquals($before, $this->reads, 'A metadata hit must not even check remote file existence.');
        $this->assertSame(100, $second['width']);
        $this->assertSame(100, $second['height']);
    }

    public function test_blade_renders_reuse_actual_non_upscaled_dimensions(): void
    {
        $this->source->put('small.png', $this->png('#ff0000', 40, 20));
        config(['thumbnails.allow_upscale' => false]);
        $blade = '<x-smart-thumbnail src="small.png" disk="source" preset="test" :variants="[]" />';
        $first = Blade::render($blade);
        $second = Blade::render($blade);
        $this->assertSame($first, $second);
        $this->assertStringContainsString('width="20"', $second);
        $this->assertStringContainsString('height="20"', $second);
        $this->assertStringContainsString(' 20w', $second);
        $this->assertSame(1, $this->reads->source);
        $this->assertSame(1, $this->reads->output);
    }

    public function test_expiry_is_bounded_and_hits_do_not_extend_it(): void
    {
        config(['thumbnails.image_data_cache_ttl' => 300]);
        $this->service()->imageData();
        $this->travel(200)->seconds();
        $this->service()->imageData();
        $this->assertSame(1, $this->reads->source);
        $this->travel(101)->seconds();
        $this->service()->imageData();
        $this->assertSame(2, $this->reads->source);
        $this->assertSame(2, $this->reads->output);
    }

    public static function stores(): array
    {
        return [['array'], ['file']];
    }

    #[DataProvider('stores')]
    public function test_preset_and_global_invalidation_preserve_unrelated_cache_data(string $store): void
    {
        config(['cache.default' => $store, 'thumbnails.presets.other' => config('thumbnails.presets.test')]);
        Cache::flush();
        Cache::put('unrelated-app-data', 'keep', 60);
        $this->service()->imageData();
        $this->service(preset: 'other')->imageData();
        $this->service()->clearCache();
        $this->service()->imageData();
        $this->service(preset: 'other')->imageData();
        $this->assertSame(3, $this->reads->source);
        CacheHelper::flush(['thumbnails']);
        $this->service()->imageData();
        $this->service(preset: 'other')->imageData();
        $this->assertSame(5, $this->reads->source);
        $this->assertSame(5, $this->reads->output);
        $this->assertSame('keep', Cache::get('unrelated-app-data'));
        Cache::flush();
    }

    public function test_source_replacements_are_bounded_until_an_explicit_refresh(): void
    {
        $first = $this->service()->imageData();
        $this->source->put('image.png', $this->png('#0000ff'));
        $this->assertSame($first, $this->service()->imageData());
        $this->assertSame(1, $this->reads->source);
        $fresh = $this->service()->imageDataFresh();
        $this->assertNotSame($first['url'], $fresh['url']);
        $this->assertSame($fresh, $this->service()->imageData());
        $this->assertSame(2, $this->reads->source);
        $this->assertSame(2, $this->reads->output);
    }

    public function test_removed_derivatives_are_repaired_by_fresh_metadata_and_purge_invalidates_metadata(): void
    {
        $service = $this->service();
        $first = $service->imageData();
        $path = $service->debugInfo()['thumbnail_path'];
        $this->output->delete($path);
        $this->assertSame($first, $this->service()->imageData());
        $this->assertFalse($this->output->exists($path));
        $this->assertSame($first, $this->service()->imageDataFresh());
        $this->assertTrue($this->output->exists($path));
        $this->assertSame(1, $service->purgePreset('test'));
        $this->service()->imageData();
        $this->assertTrue($this->output->exists($path));
        $this->assertSame(3, $this->reads->output);
    }

    public function test_url_and_url_safe_keep_immediate_source_verification(): void
    {
        $first = $this->service()->imageData();
        $this->source->put('image.png', $this->png('#0000ff'));
        $this->assertNotSame($first['url'], $this->service()->url());
        $this->source->delete('image.png');
        config(['thumbnails.placeholder_url' => '/fallback.png']);
        $this->assertSame('/fallback.png', $this->service()->urlSafe());
        $this->assertSame($first, $this->service()->imageData());
    }

    public function test_forced_regeneration_evicts_metadata_for_other_service_instances(): void
    {
        $first = $this->service()->imageData();
        $this->source->put('image.png', $this->png('#0000ff'));
        $url = $this->service()->regenerate();
        $second = $this->service()->imageData();
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertSame($url, $second['url']);
        $this->assertSame(3, $this->reads->source);
    }

    public function test_source_paths_disks_and_variants_have_distinct_entries(): void
    {
        $this->source->put('nested/image.png', $this->png('#ff0000'));
        Storage::fake('other-source')->put('image.png', $this->png('#ff0000'));
        config(['thumbnails.presets.test.variants.small' => ['smartcrop' => '50x50']]);
        $entries = [$this->service()->imageData(), $this->service('nested/image.png')->imageData(),
            $this->service(disk: 'other-source')->imageData(), $this->service()->imageData('small')];
        $this->assertCount(4, array_unique(array_column($entries, 'url')));
        $this->assertSame(50, $entries[3]['width']);
        $this->assertSame($entries[3], $this->service()->imageData('small'));
    }

    public function test_render_settings_and_url_configuration_changes_miss_the_cache(): void
    {
        $first = $this->service()->imageData();
        config(['thumbnails.presets.test.quality' => 40]);
        $second = $this->service()->imageData();
        $this->assertNotSame($first['url'], $second['url']);
        // Keep the files and warm url() entry, but install a resolved adapter with a new CDN URL.
        $config = array_replace($this->output->getConfig(), ['url' => 'https://new-cdn.example.test']);
        $changed = new FilesystemAdapter($this->output->getDriver(), $this->output->getAdapter(), $config);
        $this->countReads('output', $changed);
        $third = $this->service()->imageData();
        $this->assertStringStartsWith('https://new-cdn.example.test/', $third['url']);
        $this->assertSame(3, $this->reads->source);
        $this->assertSame(3, $this->reads->output);
    }

    public function test_scoped_parent_configuration_and_source_validation_changes_are_respected(): void
    {
        config(['filesystems.disks.source.driver' => 'scoped', 'filesystems.disks.source.disk' => 'parent',
            'filesystems.disks.parent' => ['driver' => 's3', 'bucket' => 'first']]);
        $this->service()->imageData();
        config(['filesystems.disks.parent.bucket' => 'second']);
        $this->service()->imageData();
        $this->assertSame(2, $this->reads->source);
        config(['thumbnails.max_file_size' => 1]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_file_size');
        $this->service()->imageData();
    }

    public static function disabledSettings(): array
    {
        return [[['thumbnails.cache_image_data' => false]], [['thumbnails.image_data_cache_ttl' => 0]],
            [['thumbnails.cache_thumbnails' => false]]];
    }

    #[DataProvider('disabledSettings')]
    public function test_disabling_metadata_cache_always_reads_fresh_data(array $settings): void
    {
        $first = $this->service()->imageData();
        $this->source->put('image.png', $this->png('#0000ff'));
        config($settings);
        $second = $this->service()->imageData();
        $third = $this->service()->imageData();
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertSame($second, $third);
        $this->assertSame(3, $this->reads->source);
        $this->assertSame(3, $this->reads->output);
    }

    public function test_failure_is_not_cached_and_silent_mode_is_restored_after_a_failed_refresh(): void
    {
        config(['thumbnails.placeholder_url' => '/fallback.png']);
        $service = $this->service()->silent();
        $first = $service->imageData();
        $this->source->delete('image.png');
        try {
            $service->imageDataFresh();
            $this->fail('Metadata may not cache a fallback or old snapshot dimensions.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Source image not found', $e->getMessage());
        }
        $this->assertSame('/fallback.png', $service->url(), 'The caller must remain in silent mode.');
        $this->source->put('image.png', $this->png('#0000ff'));
        $second = $this->service()->imageData();
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertStringNotContainsString('fallback', $second['url']);
    }

    public function test_invalid_derivative_dimensions_are_not_cached(): void
    {
        $service = $this->service();
        $service->url();
        $path = $service->path();
        $this->output->put($path, 'corrupt');
        try {
            $this->service()->imageData();
            $this->fail('An invalid derivative cannot supply metadata.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('dimensions', $e->getMessage());
        }
        $this->output->put($path, $this->png('#ff0000', 100, 100));
        $result = $this->service()->imageData();
        $this->assertSame(100, $result['width']);
        $this->assertSame(2, $this->reads->output);
    }

    public function test_a_missing_source_is_retried_and_never_replaced_by_cached_fallback_metadata(): void
    {
        $this->source->delete('image.png');
        config(['thumbnails.placeholder_url' => '/fallback.png']);
        $blade = '<x-smart-thumbnail src="image.png" disk="source" preset="test" :variants="[]" />';
        $this->assertStringContainsString('src="/fallback.png"', Blade::render($blade));
        $this->source->put('image.png', $this->png('#ff0000'));
        $rendered = Blade::render($blade);
        $this->assertStringNotContainsString('/fallback.png', $rendered);
        $this->assertStringContainsString('width="100"', $rendered);
        $this->assertSame(1, $this->reads->source);
        $this->assertSame(1, $this->reads->output);
    }

    public function test_a_flush_during_loading_cannot_repopulate_the_new_metadata_namespace_with_old_data(): void
    {
        $update = function () {
            $this->source->put('image.png', $this->png('#0000ff'));
            CacheHelper::flush(['test']);
        };
        $service = new class ($update) extends ThumbnailService {
            public function __construct(private \Closure $update)
            {
                parent::__construct(new \Askancy\LaravelSmartThumbnails\Services\SmartCropService());
            }

            public function url(?string $variant = null, bool $force = false): string
            {
                $url = parent::url($variant, $force);
                ($this->update)();
                return $url;
            }
        };
        $first = $service->set('test')->src('image.png', 'source')->imageData();
        $second = $this->service()->imageData();
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertSame(2, $this->reads->source);
    }

    public function test_a_fresh_read_cannot_be_overwritten_by_an_earlier_in_flight_load(): void
    {
        $updated = null;
        $refresh = function () use (&$updated) {
            $this->source->put('image.png', $this->png('#0000ff'));
            $updated = $this->service()->imageDataFresh();
        };
        $service = new class ($refresh) extends ThumbnailService {
            public function __construct(private \Closure $refresh)
            {
                parent::__construct(new \Askancy\LaravelSmartThumbnails\Services\SmartCropService());
            }

            public function url(?string $variant = null, bool $force = false): string
            {
                $url = parent::url($variant, $force);
                ($this->refresh)();
                return $url;
            }
        };
        $earlier = $service->set('test')->src('image.png', 'source')->imageData();
        $this->assertNotSame($earlier['url'], $updated['url']);
        $this->assertSame($updated, $this->service()->imageData());
        $this->assertSame(2, $this->reads->source);
    }
}
