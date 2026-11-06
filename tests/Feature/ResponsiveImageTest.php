<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

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
}
