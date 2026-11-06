<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

class ResponsiveImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::disk('local')->put('source.png', ThumbnailGenerator::createImageManager()->create(300, 200)->fill('#ff0000')->toPng()->toString());
        config(['thumbnails.presets.test.variants' => [
            'small' => ['smartcrop' => '40x40'], 'medium' => ['smartcrop' => '80x80'],
            'portrait' => ['smartcrop' => '100x200'],
        ]]);
    }

    private function image(string $blade): \DOMElement
    {
        $document = new \DOMDocument();
        $document->loadHTML(Blade::render($blade, ['alt' => 'A "quote"']));
        return $document->getElementsByTagName('img')->item(0);
    }

    public function test_component_renders_sorted_srcset_sizes_dimensions_and_attributes(): void
    {
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" sizes="(max-width: 600px) 100vw, 50vw" :alt="$alt" class="photo" loading="eager" />');
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['40', '80', '100'], $widths[1]);
        $this->assertSame('(max-width: 600px) 100vw, 50vw', $image->getAttribute('sizes'));
        $this->assertSame('100', $image->getAttribute('width'));
        $this->assertSame('100', $image->getAttribute('height'));
        $this->assertSame('A "quote"', $image->getAttribute('alt'));
        $this->assertSame('photo', $image->getAttribute('class'));
        $this->assertSame('eager', $image->getAttribute('loading'));
        $this->assertSame('async', $image->getAttribute('decoding'));
    }

    public function test_variant_selection_limits_generated_candidates(): void
    {
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" :variants="[\'small\']" />');
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['40', '100'], $widths[1]);
        $this->assertCount(2, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_component_uses_actual_widths_and_deduplicates_non_upscaled_variants(): void
    {
        Storage::disk('local')->put('small.png', ThumbnailGenerator::createImageManager()->create(20, 10)->toPng()->toString());
        config(['thumbnails.allow_upscale' => false]);
        $image = $this->image('<x-smart-thumbnail src="small.png" preset="test" disk="local" />');
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['10'], $widths[1]);
        $this->assertSame('10', $image->getAttribute('width'));
    }

    public function test_missing_source_uses_a_fallback_without_false_width_descriptors(): void
    {
        config(['thumbnails.placeholder_url' => '/fallback.png']);
        $image = $this->image('<x-smart-thumbnail src="missing.png" preset="test" disk="local" alt="Missing" />');
        $this->assertSame('/fallback.png', $image->getAttribute('src'));
        $this->assertFalse($image->hasAttribute('srcset'));
        $this->assertFalse($image->hasAttribute('width'));
    }

    private function heroPreset(): void
    {
        config(['thumbnails.presets.test.variants' => [
            'detail_hero' => ['smartcrop' => '1200x525'],
            'detail_hero_sm' => ['smartcrop' => '800x350'],
            'duplicate' => ['smartcrop' => '800x350'],
            'square' => ['smartcrop' => '100x100'],
        ]]);
    }

    public function test_main_variant_sets_src_dimensions_and_candidate_aspect_ratio(): void
    {
        $this->heroPreset();
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" :variants="[\'detail_hero\', \'detail_hero_sm\', \'duplicate\', \'square\']" />');
        $this->assertStringContainsString('source_1200_525_detail_hero.jpg', $image->getAttribute('src'));
        $this->assertSame('1200', $image->getAttribute('width'));
        $this->assertSame('525', $image->getAttribute('height'));
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['800', '1200'], $widths[1]);
        $this->assertStringContainsString($image->getAttribute('src') . ' 1200w', $image->getAttribute('srcset'));
        $this->assertStringNotContainsString('_square', $image->getAttribute('srcset'));
        $this->assertFalse($image->hasAttribute('variant'));
        $this->assertCount(4, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_explicit_empty_candidates_generate_only_the_selected_main_variant(): void
    {
        $this->heroPreset();
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" :variants="[]" />');
        $this->assertSame($image->getAttribute('src') . ' 1200w', $image->getAttribute('srcset'));
        $files = Storage::disk('local')->allFiles('test-crops');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('_detail_hero.jpg', $files[0]);
    }

    public function test_explicit_empty_candidates_also_work_with_the_default_main_image(): void
    {
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" :variants="[]" />');
        $this->assertSame($image->getAttribute('src') . ' 100w', $image->getAttribute('srcset'));
        $this->assertCount(1, Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_explicit_empty_candidates_do_not_request_other_image_data(): void
    {
        $calls = (object) ['variants' => []];
        $service = new class ($calls) extends ThumbnailService {
            public function __construct(private object $calls)
            {
                parent::__construct(new \Askancy\LaravelSmartThumbnails\Services\SmartCropService());
            }

            public function imageData(?string $variant = null): array
            {
                $this->calls->variants[] = $variant;
                return parent::imageData($variant);
            }
        };
        $this->app->instance('laravel-smart-thumbnails', $service);
        $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="small" :variants="[]" />');
        $this->assertSame(['small'], $calls->variants);
    }

    public function test_null_candidates_discover_variants_relative_to_the_selected_main_image(): void
    {
        $this->heroPreset();
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" :variants="null" />');
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['800', '1200'], $widths[1]);
    }

    public function test_main_variant_uses_actual_dimensions_when_upscaling_is_disabled(): void
    {
        $this->heroPreset();
        config(['thumbnails.allow_upscale' => false]);
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" />');
        $this->assertSame('300', $image->getAttribute('width'));
        $this->assertSame('131', $image->getAttribute('height'));
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['300'], $widths[1]);
    }

    public function test_failed_main_variant_uses_its_fallback_without_generating_the_default_crop(): void
    {
        $this->heroPreset();
        config(['thumbnails.presets.test.variants.detail_hero.format' => 'unsupported', 'thumbnails.placeholder_url' => '/fallback.png']);
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" />');
        $this->assertSame('/fallback.png', $image->getAttribute('src'));
        $this->assertFalse($image->hasAttribute('width'));
        $this->assertFalse($image->hasAttribute('height'));
        $this->assertFalse($image->hasAttribute('srcset'));
        $this->assertSame([], Storage::disk('local')->allFiles('test-crops'));
    }

    public function test_unavailable_alternate_does_not_hide_the_selected_main_variant(): void
    {
        $this->heroPreset();
        $image = $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="detail_hero" :variants="[\'unknown\', \'detail_hero_sm\']" />');
        $this->assertStringContainsString('_detail_hero.jpg', $image->getAttribute('src'));
        preg_match_all('/ (\d+)w/', $image->getAttribute('srcset'), $widths);
        $this->assertSame(['800', '1200'], $widths[1]);
    }

    public function test_rendering_preserves_the_shared_service_source_and_silent_state(): void
    {
        $service = app(ThumbnailService::class)->set('test')->src('shared-missing.png', 'local')->silent();
        config(['thumbnails.placeholder_url' => '/fallback.png']);
        $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="small" :variants="[]" />');
        $this->assertFalse($service->exists());
        $this->assertSame('/fallback.png', $service->url());
        $service->strict();
        $this->image('<x-smart-thumbnail src="source.png" preset="test" disk="local" variant="unknown" />');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('shared-missing.png');
        $service->url();
    }
}
