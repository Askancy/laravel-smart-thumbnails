<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;

class SmartCropServiceTest extends TestCase
{
    public function test_crop_follows_content_at_each_edge(): void
    {
        $manager = ThumbnailGenerator::createImageManager();
        foreach (['left', 'right', 'top', 'bottom'] as $edge) {
            $horizontal = in_array($edge, ['left', 'right']);
            $image = $manager->create($horizontal ? 300 : 100, $horizontal ? 100 : 300)->fill('#000000');
            $x = $horizontal ? ($edge === 'left' ? 0 : 270) : 25;
            $y = $horizontal ? 25 : ($edge === 'top' ? 0 : 270);
            $bitmap = $image->core()->native();
            imagefilledrectangle($bitmap, $x, $y, $x + ($horizontal ? 29 : 49), $y + ($horizontal ? 49 : 29), imagecolorallocate($bitmap, 255, 255, 255));

            $result = (new SmartCropService())->smartCrop($image, 100, 100);
            $visible = 0;
            for ($row = 0; $row < 100; $row++) {
                for ($column = 0; $column < 100; $column++) {
                    if ((imagecolorat($result->core()->native(), $column, $row) & 0xffffff) === 0xffffff) {
                        $visible++;
                    }
                }
            }
            $this->assertGreaterThanOrEqual(1400, $visible, "The subject at {$edge} must remain visible.");
        }
    }

    public function test_uniform_transparent_small_and_extreme_images_produce_exact_dimensions(): void
    {
        foreach ([[1, 1], [1, 300], [300, 1], [300, 200], [2000, 1000]] as [$width, $height]) {
            $image = ThumbnailGenerator::createImageManager()->create($width, $height);
            $result = (new SmartCropService())->smartCrop($image, 40, 60);
            $this->assertSame(40, $result->width());
            $this->assertSame(60, $result->height());
        }
    }

    public function test_same_aspect_ratio_preserves_both_edges(): void
    {
        $image = ThumbnailGenerator::createImageManager()->create(200, 100)->fill('#ff0000');
        $bitmap = $image->core()->native();
        imagefilledrectangle($bitmap, 100, 0, 199, 99, imagecolorallocate($bitmap, 0, 0, 255));
        $result = (new SmartCropService())->smartCrop($image, 100, 50);
        $this->assertSame('ff0000', $result->pickColor(0, 25)->toHex());
        $this->assertSame('0000ff', $result->pickColor(99, 25)->toHex());
    }

    public function test_invalid_dimensions_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SmartCropService())->smartCrop(ThumbnailGenerator::createImageManager()->create(10, 10), 0, 10);
    }
}
