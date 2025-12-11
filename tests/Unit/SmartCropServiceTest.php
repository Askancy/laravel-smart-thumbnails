<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;

class SmartCropServiceTest extends TestCase
{
    protected $smartCropService;
    protected $imageManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->smartCropService = new SmartCropService();
        $this->imageManager = ThumbnailGenerator::createImageManager();
    }

    /** @test */
    public function it_can_crop_landscape_image()
    {
        // Create test image 200x100 (landscape)
        $image = $this->imageManager->create(200, 100);

        // Crop to square 100x100
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    /** @test */
    public function it_can_crop_portrait_image()
    {
        // Create test image 100x200 (portrait)
        $image = $this->imageManager->create(100, 200);

        // Crop to square 100x100
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    /** @test */
    public function it_skips_crop_for_matching_dimensions()
    {
        // Create image already at target dimensions
        $image = $this->imageManager->create(100, 100);

        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    /** @test */
    public function it_calculates_optimal_crop_for_landscape()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateOptimalCrop');
        $method->setAccessible(true);

        // Test landscape to square
        $result = $method->invoke($this->smartCropService, 200, 100, 100, 100);

        $this->assertTrue($result['needsCrop']);
        $this->assertEquals(100, $result['cropWidth']);
        $this->assertEquals(100, $result['cropHeight']);

        // Should crop horizontally, centered based on rule of thirds
        $this->assertGreaterThanOrEqual(0, $result['cropX']);
        $this->assertEquals(0, $result['cropY']);
    }

    /** @test */
    public function it_calculates_optimal_crop_for_portrait()
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

        // Should prefer upper third (not center)
        $this->assertGreaterThan(0, $result['cropY']);
        $this->assertLessThan(50, $result['cropY']); // Should be in upper portion
    }

    /** @test */
    public function it_skips_crop_when_aspect_ratios_match()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateOptimalCrop');
        $method->setAccessible(true);

        // Test with matching aspect ratios (both 2:1)
        $result = $method->invoke($this->smartCropService, 200, 100, 100, 50);

        $this->assertFalse($result['needsCrop']);
        $this->assertEquals(200, $result['cropWidth']);
        $this->assertEquals(100, $result['cropHeight']);
        $this->assertEquals(0, $result['cropX']);
        $this->assertEquals(0, $result['cropY']);
    }

    /** @test */
    public function it_calculates_smart_x_position_using_rule_of_thirds()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateSmartX');
        $method->setAccessible(true);

        $totalWidth = 300;
        $cropWidth = 100;

        $x = $method->invoke($this->smartCropService, $totalWidth, $cropWidth);

        // Available space = 200, rule of thirds = 200/3 ≈ 67
        $expectedX = (int) round(($totalWidth - $cropWidth) / 3);
        $this->assertEquals($expectedX, $x);
    }

    /** @test */
    public function it_returns_zero_x_when_no_space_available()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateSmartX');
        $method->setAccessible(true);

        $totalWidth = 100;
        $cropWidth = 100;

        $x = $method->invoke($this->smartCropService, $totalWidth, $cropWidth);

        $this->assertEquals(0, $x);
    }

    /** @test */
    public function it_calculates_smart_y_position_preferring_upper_third()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateSmartY');
        $method->setAccessible(true);

        $totalHeight = 300;
        $cropHeight = 100;

        $y = $method->invoke($this->smartCropService, $totalHeight, $cropHeight);

        // Available space = 200, upper third = 200/3 ≈ 67
        $expectedY = (int) round(($totalHeight - $cropHeight) / 3);
        $this->assertEquals($expectedY, $y);

        // Should be in upper portion
        $this->assertLessThan($totalHeight / 2, $y);
    }

    /** @test */
    public function it_returns_zero_y_when_no_space_available()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateSmartY');
        $method->setAccessible(true);

        $totalHeight = 100;
        $cropHeight = 100;

        $y = $method->invoke($this->smartCropService, $totalHeight, $cropHeight);

        $this->assertEquals(0, $y);
    }

    /** @test */
    public function it_calculates_energy_map_with_rule_of_thirds_points()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateEnergyMap');
        $method->setAccessible(true);

        $image = $this->imageManager->create(300, 300);

        $energyMap = $method->invoke($this->smartCropService, $image);

        $this->assertIsArray($energyMap);
        $this->assertNotEmpty($energyMap);

        // Should have multiple interest points
        $this->assertGreaterThanOrEqual(4, count($energyMap));

        // Each point should have x, y, and weight
        foreach ($energyMap as $point) {
            $this->assertArrayHasKey('x', $point);
            $this->assertArrayHasKey('y', $point);
            $this->assertArrayHasKey('weight', $point);
        }
    }

    /** @test */
    public function it_finds_interest_points_in_image()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('findInterestPoints');
        $method->setAccessible(true);

        $image = $this->imageManager->create(300, 300);

        $interestPoint = $method->invoke($this->smartCropService, $image);

        $this->assertIsArray($interestPoint);
        $this->assertArrayHasKey('x', $interestPoint);
        $this->assertArrayHasKey('y', $interestPoint);
        $this->assertArrayHasKey('weight', $interestPoint);

        // Weight should be positive
        $this->assertGreaterThan(0, $interestPoint['weight']);
    }

    /** @test */
    public function it_crops_wide_image_to_narrow_aspect_ratio()
    {
        // Very wide image
        $image = $this->imageManager->create(400, 100);

        // Crop to portrait
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 200);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(200, $croppedImage->height());
    }

    /** @test */
    public function it_crops_tall_image_to_wide_aspect_ratio()
    {
        // Very tall image
        $image = $this->imageManager->create(100, 400);

        // Crop to landscape
        $croppedImage = $this->smartCropService->smartCrop($image, 200, 100);

        $this->assertEquals(200, $croppedImage->width());
        $this->assertEquals(100, $croppedImage->height());
    }

    /** @test */
    public function it_handles_very_small_images()
    {
        // Tiny image
        $image = $this->imageManager->create(10, 10);

        // Crop to even smaller
        $croppedImage = $this->smartCropService->smartCrop($image, 5, 5);

        $this->assertEquals(5, $croppedImage->width());
        $this->assertEquals(5, $croppedImage->height());
    }

    /** @test */
    public function it_handles_very_large_aspect_ratio_differences()
    {
        // Extreme landscape
        $image = $this->imageManager->create(1000, 100);

        // Extreme portrait target
        $croppedImage = $this->smartCropService->smartCrop($image, 100, 1000);

        $this->assertEquals(100, $croppedImage->width());
        $this->assertEquals(1000, $croppedImage->height());
    }

    /** @test */
    public function it_maintains_image_quality_after_crop()
    {
        $image = $this->imageManager->create(200, 200);

        $croppedImage = $this->smartCropService->smartCrop($image, 100, 100);

        // Image should still be a valid ImageInterface
        $this->assertNotNull($croppedImage->width());
        $this->assertNotNull($croppedImage->height());
    }

    /** @test */
    public function crop_position_is_consistent_for_same_dimensions()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateOptimalCrop');
        $method->setAccessible(true);

        // Call multiple times with same parameters
        $result1 = $method->invoke($this->smartCropService, 300, 200, 100, 100);
        $result2 = $method->invoke($this->smartCropService, 300, 200, 100, 100);

        // Results should be identical
        $this->assertEquals($result1, $result2);
    }

    /** @test */
    public function rule_of_thirds_produces_better_crop_than_center()
    {
        $reflection = new \ReflectionClass($this->smartCropService);
        $method = $reflection->getMethod('calculateSmartX');
        $method->setAccessible(true);

        $totalWidth = 300;
        $cropWidth = 100;

        $ruleOfThirdsX = $method->invoke($this->smartCropService, $totalWidth, $cropWidth);
        $centerX = ($totalWidth - $cropWidth) / 2;

        // Rule of thirds should be different from center
        $this->assertNotEquals($centerX, $ruleOfThirdsX);

        // Rule of thirds should be in the left third
        $this->assertLessThan($centerX, $ruleOfThirdsX);
    }
}
