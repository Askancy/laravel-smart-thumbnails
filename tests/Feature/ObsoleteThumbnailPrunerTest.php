<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Services\ObsoleteThumbnailPruner;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailManifest;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ObsoleteThumbnailPrunerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config(['thumbnails.presets.test.format' => 'png', 'thumbnails.generation_lock_wait' => 0]);
        $this->source('#ff0000');
    }

    private function source(string $color): void
    {
        Storage::disk('local')->put('source.png', ThumbnailGenerator::createImageManager()->create(200, 100)->fill($color)->toPng()->toString());
    }

    private function service(): ThumbnailService
    {
        return (new ThumbnailService(new SmartCropService()))->set('test')->src('source.png', 'local')->strict();
    }

    private function oldVersion(?string $variant = null): string
    {
        $this->travel(-40)->days();
        try {
            $service = $this->service();
            $service->url($variant);
            return $service->path($variant);
        } finally {
            $this->travelBack();
        }
    }

    public function test_preview_changes_nothing_and_actual_cleanup_preserves_current_and_untracked_files(): void
    {
        $old = $this->oldVersion();
        $this->source('#0000ff');
        $service = $this->service();
        $service->url();
        $current = $service->path();
        Storage::disk('local')->put('test-crops/legacy_100_100.png', 'legacy');
        Storage::disk('local')->put('test-crops/original.png', 'unrelated');
        $before = Storage::disk('local')->allFiles();
        $this->artisan('thumbnails:prune', ['--dry-run' => true])->expectsOutputToContain('Would delete:')->assertExitCode(0);
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->artisan('thumbnails:prune')->assertExitCode(0);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertMissing(ThumbnailManifest::path('local', $old));
        Storage::disk('local')->assertExists($current);
        Storage::disk('local')->assertExists('test-crops/legacy_100_100.png');
        Storage::disk('local')->assertExists('test-crops/original.png');
    }

    public function test_an_old_but_current_version_is_never_removed(): void
    {
        $current = $this->oldVersion();
        $result = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $result['current']);
        $this->assertSame(0, $result['deleted']);
        Storage::disk('local')->assertExists($current);
    }

    public function test_recent_obsolete_versions_survive_until_the_retention_period_passes(): void
    {
        $this->service()->url();
        $old = $this->service()->path();
        $this->source('#0000ff');
        $this->artisan('thumbnails:prune')->assertExitCode(0);
        Storage::disk('local')->assertExists($old);
        $this->artisan('thumbnails:prune', ['--days' => '0'])->assertExitCode(0);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_quality_changes_and_removed_variants_are_detected(): void
    {
        config(['thumbnails.presets.test.variants.full' => ['crop_mode' => 'dont_crop']]);
        $main = $this->oldVersion();
        $variant = $this->oldVersion('full');
        config(['thumbnails.presets.test.variants' => []]);
        $first = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $first['deleted']);
        Storage::disk('local')->assertMissing($variant);
        Storage::disk('local')->assertExists($main);
        config(['thumbnails.presets.test.quality' => 40]);
        $second = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $second['deleted']);
        Storage::disk('local')->assertMissing($main);
    }

    public function test_modified_output_and_invalid_source_are_preserved(): void
    {
        $old = $this->oldVersion();
        $this->source('#0000ff');
        $binary = Storage::disk('local')->get($old);
        Storage::disk('local')->put($old, 'replaced-outside-the-package');
        $first = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $first['modified']);
        Storage::disk('local')->assertExists($old);
        Storage::disk('local')->put($old, $binary);
        Storage::disk('local')->put('source.png', 'unreadable-image');
        $second = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $second['errors']);
        Storage::disk('local')->assertExists($old);
    }

    public function test_missing_sources_and_removed_presets_can_be_pruned(): void
    {
        $old = $this->oldVersion();
        Storage::disk('local')->delete('source.png');
        $first = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $first['deleted']);
        Storage::disk('local')->assertMissing($old);
        $this->source('#ff0000');
        $old = $this->oldVersion();
        $presets = config('thumbnails.presets');
        unset($presets['test']);
        config(['thumbnails.presets' => $presets]);
        $second = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
        $this->assertSame(1, $second['deleted']);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_busy_versions_are_skipped_using_the_generation_lock(): void
    {
        $old = $this->oldVersion();
        $this->source('#0000ff');
        $lock = Cache::store()->getStore()->lock(GenerationLock::name('local', $old), 300);
        $this->assertTrue($lock->get());
        try {
            $result = (new ObsoleteThumbnailPruner())->prune(null, 30, false);
            $this->assertSame(1, $result['busy']);
            $this->assertSame(0, $result['deleted']);
            Storage::disk('local')->assertExists($old);
        } finally {
            $lock->release();
        }
        $this->assertSame(1, (new ObsoleteThumbnailPruner())->prune(null, 30, false)['deleted']);
    }

    public function test_bad_metadata_cannot_delete_an_unrelated_file(): void
    {
        $old = $this->oldVersion();
        $manifest = ThumbnailManifest::path('local', $old);
        $record = ThumbnailManifest::read($manifest);
        $record['path'] = 'valuable.png';
        Storage::disk('local')->put('valuable.png', 'keep');
        Storage::disk('local')->put($manifest, json_encode($record));
        $this->artisan('thumbnails:prune')->assertExitCode(1);
        $this->assertSame('keep', Storage::disk('local')->get('valuable.png'));
        Storage::disk('local')->assertExists($old);
    }

    public function test_preset_filter_and_invalid_retention_are_respected(): void
    {
        $old = $this->oldVersion();
        $this->source('#0000ff');
        $this->artisan('thumbnails:prune', ['--days' => '-1'])->assertExitCode(1);
        Storage::disk('local')->assertExists($old);
        $result = (new ObsoleteThumbnailPruner())->prune('another-preset', 30, false);
        $this->assertSame(0, $result['examined']);
        Storage::disk('local')->assertExists($old);
        $this->artisan('thumbnails:prune', ['preset' => 'test'])->assertExitCode(0);
        Storage::disk('local')->assertMissing($old);
    }
}
