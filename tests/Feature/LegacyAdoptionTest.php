<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Jobs\GenerateThumbnailJob;
use Askancy\LaravelSmartThumbnails\Services\ObsoleteThumbnailPruner;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Tests\Fixtures\Legacy43\ThumbnailIdentity as LegacyIdentity;
use Askancy\LaravelSmartThumbnails\Tests\Fixtures\Legacy43\ThumbnailManifest as LegacyManifest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;

require_once __DIR__ . '/../fixtures/legacy43/ThumbnailIdentity.php';
require_once __DIR__ . '/../fixtures/legacy43/ThumbnailManifest.php';

class LegacyAdoptionTest extends TestCase
{
    private int $reads = 0;
    private int $bytes = 0;
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMockingConsoleOutput();
        Cache::flush();
        Storage::fake('local');
        $source = Storage::fake('remote');
        config([
            'filesystems.disks.remote' => ['driver' => 'scoped', 'disk' => 'spaces', 'prefix' => 'originals'],
            'filesystems.disks.spaces' => ['driver' => 's3', 'bucket' => 'images'],
            'thumbnails.presets.test.subdirectory_strategy' => 'date_based',
            'thumbnails.presets.test.smart_crop_enabled' => false,
            'thumbnails.presets.test.variants.small' => ['smartcrop' => '50x50'],
        ]);
        $this->original = ThumbnailGenerator::createImageManager()->create(120, 80)->fill('#ff0000')->toPng()->toString();
        $source->put('image.png', $this->original);
        $proxy = Mockery::mock($source);
        $proxy->shouldReceive('readStream')->andReturnUsing(function ($path) use ($source) {
            $this->reads++;
            $this->bytes += $source->size($path);
            return $source->readStream($path);
        });
        $proxy->shouldReceive('get')->never();
        Storage::set('remote', $proxy);
    }

    private function service(): ThumbnailService
    {
        return (new ThumbnailService(new SmartCropService()))->set('test')->src('image.png', 'remote')->strict();
    }

    private function fixture(?string $variant = null): array
    {
        $config = config('thumbnails.presets.test');
        if ($variant !== null) {
            $config = array_replace_recursive($config, $config['variants'][$variant]);
        }
        $config = LegacyIdentity::settings($config);
        $hash = hash('sha256', $this->original);
        $path = LegacyIdentity::path('image.png', 'remote', $config, $variant, $hash);
        [$width, $height] = array_map('intval', explode('x', $config['smartcrop']));
        $image = ThumbnailGenerator::createImageManager()->read($this->original);
        $image = ThumbnailGenerator::transform($image, $width, $height, $config, new SmartCropService());
        $binary = ThumbnailGenerator::convertFormat($image, $config['format'], $config['quality'], $config);
        Storage::disk('local')->put($path, $binary);
        LegacyManifest::write('local', $path, $binary, LegacyManifest::record('image.png', 'remote', 'test', $variant, $config, $hash));
        return [$path, LegacyManifest::path('local', $path)];
    }

    private function migrate(array $manifests, array $options = []): array
    {
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = Artisan::call('thumbnails:adopt-legacy', ['manifests' => $manifests] + $options, $buffer);
        $output = $buffer->fetch();
        $this->assertSame(0, $code, $output);
        $this->assertSame(1, preg_match('/\{"reused"[^\r\n]+\}/', $output, $matches), $output);
        return json_decode($matches[0], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_legacy_urls_survive_flushes_jobs_responsive_and_pruning_without_source_reads_or_output_writes(): void
    {
        [$path, $manifest] = $this->fixture();
        [$small, $smallManifest] = $this->fixture('small');
        $before = Storage::disk('local')->allFiles();
        $binary = Storage::disk('local')->get($path);
        $output = Storage::disk('local');
        $proxy = Mockery::mock($output);
        $proxy->shouldReceive('put')->andReturnUsing(function ($target, $contents, $options = []) use ($output) {
            $this->assertFalse(str_starts_with($target, 'test-crops/'), 'Migration or lookup uploaded a derivative.');
            return $output->put($target, $contents, $options);
        });
        Storage::set('local', $proxy);
        $this->assertSame(2, $this->migrate([$manifest, $smallManifest], ['--read-sources' => true])['reused']);
        $this->assertSame([2, 2 * strlen($this->original)], [$this->reads, $this->bytes]);
        $this->assertSame(0, $this->migrate([$manifest, $smallManifest], ['--read-sources' => true])['source_reads']);
        for ($i = 0; $i < 3; $i++) {
            Cache::flush();
            $this->travel(2)->days();
            $service = $this->service();
            $this->assertSame($path, $service->path());
            $this->assertTrue($service->exists());
            $this->assertSame(Storage::disk('local')->url($path), $service->url());
            $this->assertSame(Storage::disk('local')->url($small), $service->urlSafe('small'));
            $this->assertSame($service->url(), $service->imageData()['url']);
            Blade::render('<x-smart-thumbnail src="image.png" disk="remote" preset="test" :variants="[\'small\']" />');
            (new GenerateThumbnailJob('image.png', 'remote', 'test', config('thumbnails.presets.test'), 'unused', null, false, true))
                ->handle(new SmartCropService());
        }
        $counts = (new ObsoleteThumbnailPruner())->prune('test', 0, false);
        $this->assertSame(2, $counts['current']);
        $this->assertSame(0, $counts['deleted']);
        $this->assertSame([2, 2 * strlen($this->original)], [$this->reads, $this->bytes]);
        $this->assertSame($binary, Storage::disk('local')->get($path));
        $this->assertSame($before, array_values(array_filter(Storage::disk('local')->allFiles(), fn ($file) => !str_contains($file, '-adoptions/'))));
    }

    public function test_dry_run_and_missing_permission_do_not_persist_adoptions(): void
    {
        [$path, $manifest] = $this->fixture();
        $this->assertSame(1, $this->migrate([$manifest])['ambiguous']);
        $this->assertSame(0, $this->reads);
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true, '--dry-run' => true])['reused']);
        $this->assertNotSame($path, $this->service()->path());
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['reused']);
        $this->assertSame($path, $this->service()->path());
    }

    public function test_changed_settings_and_storage_never_select_the_legacy_derivative(): void
    {
        [$path, $manifest] = $this->fixture();
        $original = config('thumbnails.presets.test');
        foreach (['quality' => 40, 'allow_upscale' => false, 'crop_mode' => 'dont_crop', 'format' => 'webp'] as $key => $value) {
            config(['thumbnails.presets.test' => array_replace($original, [$key => $value])]);
            $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['ambiguous']);
        }
        config(['thumbnails.presets.test' => $original]);
        $this->migrate([$manifest], ['--read-sources' => true]);
        config(['filesystems.disks.spaces.bucket' => 'other']);
        $this->assertNotSame($path, $this->service()->path());
    }

    public function test_missing_outputs_force_fresh_and_invalidation_keep_correct_ownership(): void
    {
        [$path, $manifest] = $this->fixture();
        $this->migrate([$manifest], ['--read-sources' => true]);
        Storage::disk('local')->delete($path);
        $this->assertFalse($this->service()->exists());
        $this->service()->url();
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame($path, $this->service()->path());
        $this->service()->regenerate();
        $this->service()->imageDataFresh();
        $this->assertSame(hash('sha256', Storage::disk('local')->get($path)), ThumbnailManifest::read($manifest)['output_hash']);
        $this->service()->invalidateSource();
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertNotSame($path, $this->service()->path());
        $this->assertFalse($this->service()->exists());
    }

    public function test_missing_modified_and_unindexed_files_are_not_adopted(): void
    {
        [$path, $manifest] = $this->fixture();
        $binary = Storage::disk('local')->get($path);
        Storage::disk('local')->delete($path);
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['missing']);
        $this->assertSame(0, $this->reads);
        Storage::disk('local')->put($path, 'modified');
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['ambiguous']);
        Storage::disk('local')->put($path, $binary);
        Storage::disk('local')->delete($manifest);
        $this->assertNotSame($path, $this->service()->path());
        $this->service()->url();
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    public function test_migration_is_resumable_and_serialized_with_workers(): void
    {
        [$path, $manifest] = $this->fixture();
        config(['thumbnails.generation_lock_wait' => 0]);
        ThumbnailManifest::withSourceLock('image.png', 'remote', function () use ($manifest) {
            $this->assertSame(1, Artisan::call('thumbnails:adopt-legacy', ['manifests' => [$manifest], '--read-sources' => true]));
            $this->assertSame(0, $this->reads);
        });
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['reused']);
        $this->assertSame($path, $this->service()->path());
        $this->assertSame(0, $this->migrate([$manifest], ['--read-sources' => true])['source_reads']);
    }

    public function test_changed_original_and_invalid_content_are_never_adopted(): void
    {
        [$path, $manifest] = $this->fixture();
        Storage::disk('remote')->put('image.png', ThumbnailGenerator::createImageManager()->create(120, 80)->fill('#0000ff')->toPng()->toString());
        $this->assertSame(1, $this->migrate([$manifest], ['--read-sources' => true])['ambiguous']);
        Storage::disk('remote')->put('image.png', 'invalid');
        $this->assertSame(1, Artisan::call('thumbnails:adopt-legacy', ['manifests' => [$manifest], '--read-sources' => true]));
        $this->assertNotSame($path, $this->service()->path());
        $this->assertTrue(Storage::disk('local')->exists($path));
    }
}
