<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Intervention\Image\ImageManagerStatic as Image;

class SmartCropServiceTest extends TestCase
{
    protected $smartCropService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCropService = new SmartCropService();
    }

    public function test_can_crop_landscape_image()
    {
        // Crea un'immagine di test 200x100 (landscape)
        $image = Image::canvas(200, 100, '#ff0000');

        // Crop a quadrato 100x100
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    public function test_can_crop_portrait_image()
    {
        // Crea un'immagine di test 100x200 (portrait)
        $image = Image::canvas(100, 200, '#00ff00');

        // Crop a quadrato 100x100
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    public function test_no_crop_needed_for_same_dimensions()
    {
        // Crea un'immagine già delle dimensioni target
        $image = Image::canvas(100, 100, '#0000ff');

        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    public function test_calculate_optimal_crop_landscape()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateOptimalCrop');
        $method->setAccessible(true);

        // Test landscape to square
        $result = $method->invoke($this->smartCropService, 200, 100, 100, 100);

        $this->assertTrue($result['needsCrop']);
        $this->assertEquals(100, $result['cropWidth']);
        $this->assertEquals(100, $result['cropHeight']);
        $this->assertEquals(50, $result['cropX']); // Centro orizzontalmente
        $this->assertEquals(0, $result['cropY']);
    }

    public function test_calculate_optimal_crop_portrait()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateOptimalCrop');
        $method->setAccessible(true);

        // Test portrait to square
        $result = $method->invoke($this->smartCropService, 100, 200, 100, 100);

        $this->assertTrue($result['needsCrop']);
        $this->assertEquals(100, $result['cropWidth']);
        $this->assertEquals(100, $result['cropHeight']);
        $this->assertEquals(0, $result['cropX']);
        $this->assertGreaterThan(0, $result['cropY']); // Non al centro, ma più in alto
    }
}
