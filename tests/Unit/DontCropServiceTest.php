<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Services\DontCropService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;

class DontCropServiceTest extends TestCase
{
    public function test_contain_preserves_all_source_edges_and_does_not_mutate_the_source(): void
    {
        foreach ([[60, 30], [30, 60]] as [$width, $height]) {
            $image = ThumbnailGenerator::createImageManager()->create($width, $height)->fill('#ff0000');
            $bitmap = $image->core()->native();
            $corners = [
                [0, 0, [0, 255, 0], '00ff00'],
                [$width - 3, 0, [0, 0, 255], '0000ff'],
                [0, $height - 3, [255, 255, 0], 'ffff00'],
                [$width - 3, $height - 3, [255, 0, 255], 'ff00ff'],
            ];
            foreach ($corners as [$x, $y, $rgb, $hex]) {
                imagefilledrectangle($bitmap, $x, $y, $x + 2, $y + 2, imagecolorallocate($bitmap, ...$rgb));
            }
            $result = (new DontCropService())->contain($image, 60, 60, '#0000ff');
            $this->assertSame(60, $result->width());
            $this->assertSame(60, $result->height());
            $this->assertSame($width, $image->width());
            $this->assertSame($height, $image->height());
            $this->assertSame('0000ff', $result->pickColor(0, 0)->toHex());
            foreach ($corners as [$x, $y, $rgb, $hex]) {
                $this->assertSame($hex, $result->pickColor($x + ($width === 60 ? 0 : 15), $y + ($height === 60 ? 0 : 15))->toHex());
            }
        }
    }

    public function test_gradient_is_fitted_to_source_colors_in_both_directions(): void
    {
        foreach (['vertical', 'horizontal'] as $direction) {
            $vertical = $direction === 'vertical';
            $image = ThumbnailGenerator::createImageManager()->create($vertical ? 60 : 30, $vertical ? 30 : 60);
            $bitmap = $image->core()->native();
            for ($i = 0; $i < 30; $i++) {
                $red = (int) round(255 * $i / 29);
                $color = imagecolorallocate($bitmap, $red, 0, 255 - $red);
                imageline($bitmap, $vertical ? 0 : $i, $vertical ? $i : 0, $vertical ? 59 : $i, $vertical ? $i : 59, $color);
            }
            $service = new DontCropService();
            $gradient = $service->fitGradient($image, $direction);
            $this->assertSame('#0000ff', $gradient['start']);
            $this->assertSame('#ff0000', $gradient['end']);
            $result = $service->contain($image, 60, 60);
            $start = $result->pickColor(0, 0)->toArray();
            $end = $result->pickColor(59, 59)->toArray();
            $this->assertLessThanOrEqual(3, $start[0]);
            $this->assertGreaterThanOrEqual(252, $start[2]);
            $this->assertGreaterThanOrEqual(252, $end[0]);
            $this->assertLessThanOrEqual(3, $end[2]);
        }
    }

    public function test_palette_and_dominant_background_follow_color_frequency(): void
    {
        $image = ThumbnailGenerator::createImageManager()->create(60, 30)->fill('#ff0000');
        $bitmap = $image->core()->native();
        imagefilledrectangle($bitmap, 0, 0, 9, 29, imagecolorallocate($bitmap, 0, 0, 255));
        $service = new DontCropService();
        $this->assertSame(['#ff0000', '#0000ff'], $service->getPalette($image, 2));
        $result = $service->contain($image, 60, 60, 'dominant');
        $this->assertSame('ff0000', $result->pickColor(0, 0)->toHex());
        $this->assertSame('0000ff', $result->pickColor(0, 20)->toHex());
    }

    public function test_transparent_pixels_do_not_pollute_the_palette_or_gradient(): void
    {
        $image = ThumbnailGenerator::createImageManager()->create(30, 30);
        $service = new DontCropService();
        $this->assertSame(['#ffffff'], $service->getPalette($image));
        $this->assertSame('#ffffff', $service->fitGradient($image)['start']);
        $this->assertSame('ffffff', $service->contain($image, 60, 60)->pickColor(0, 0)->toHex());
    }

    public function test_transparent_background_can_be_selected_explicitly(): void
    {
        $image = ThumbnailGenerator::createImageManager()->create(60, 30)->fill('#ff0000');
        $result = (new DontCropService())->contain($image, 60, 60, 'transparent');
        $this->assertTrue($result->pickColor(0, 0)->isClear());
        $this->assertSame('ff0000', $result->pickColor(30, 30)->toHex());
    }

    public function test_invalid_palette_size_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DontCropService())->getPalette(ThumbnailGenerator::createImageManager()->create(10, 10), 0);
    }
}
