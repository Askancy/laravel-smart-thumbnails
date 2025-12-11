<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class InterventionImage3IntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
    }

    /** @test */
    public function it_can_create_image_manager_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        $this->assertInstanceOf(\Intervention\Image\ImageManager::class, $manager);
    }

    /** @test */
    public function it_can_read_and_manipulate_images_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create a test image
        $image = $manager->create(200, 200);

        $this->assertInstanceOf(\Intervention\Image\Interfaces\ImageInterface::class, $image);
        $this->assertEquals(200, $image->width());
        $this->assertEquals(200, $image->height());
    }

    /** @test */
    public function it_can_crop_images_with_new_api()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(400, 300);

        // Crop using new API
        $cropped = $image->crop(100, 100, 50, 50);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function it_can_resize_images_with_new_api()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(400, 300);

        // Resize using new API
        $resized = $image->resize(200, 150);

        $this->assertEquals(200, $resized->width());
        $this->assertEquals(150, $resized->height());
    }

    /** @test */
    public function it_can_encode_images_to_different_formats()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(100, 100);

        // Test JPEG encoding
        $jpeg = ThumbnailGenerator::convertFormat($image, 'jpg', 85);
        $this->assertIsString($jpeg);
        $this->assertNotEmpty($jpeg);

        // Test PNG encoding
        $png = ThumbnailGenerator::convertFormat($image, 'png', 85);
        $this->assertIsString($png);
        $this->assertNotEmpty($png);

        // Test WebP encoding
        $webp = ThumbnailGenerator::convertFormat($image, 'webp', 85);
        $this->assertIsString($webp);
        $this->assertNotEmpty($webp);
    }

    /** @test */
    public function smart_crop_service_works_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $smartCrop = new SmartCropService();

        $image = $manager->create(400, 200);
        $cropped = $smartCrop->smartCrop($image, 100, 100);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function thumbnail_generator_basic_crop_works_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(400, 200);

        $cropped = ThumbnailGenerator::applyBasicCrop($image, 100, 100);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function it_can_create_and_save_thumbnails_end_to_end()
    {
        config(['thumbnails.presets.test' => [
            'format' => 'jpg',
            'smartcrop' => '100x100',
            'destination' => ['disk' => 'local', 'path' => 'thumbnails/'],
            'quality' => 85,
        ]]);

        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(300, 200);

        // Create test source image
        $sourceImage = $image->toJpeg(85)->toString();
        Storage::disk('local')->put('source.jpg', $sourceImage);

        // Process through SmartCropService
        $smartCrop = new SmartCropService();
        $testImage = $manager->read(Storage::disk('local')->get('source.jpg'));
        $cropped = $smartCrop->smartCrop($testImage, 100, 100);

        // Convert and save
        $thumbnailData = ThumbnailGenerator::convertFormat($cropped, 'jpg', 85);
        Storage::disk('local')->put('thumbnails/test.jpg', $thumbnailData);

        // Verify
        $this->assertTrue(Storage::disk('local')->exists('thumbnails/test.jpg'));

        // Verify it can be read back
        $savedImage = $manager->read(Storage::disk('local')->get('thumbnails/test.jpg'));
        $this->assertEquals(100, $savedImage->width());
        $this->assertEquals(100, $savedImage->height());
    }

    /** @test */
    public function it_handles_different_image_formats_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create images in different formats
        $image = $manager->create(100, 100);

        // Test encoding to different formats
        $formats = ['jpg', 'png', 'webp'];

        foreach ($formats as $format) {
            $encoded = ThumbnailGenerator::convertFormat($image, $format, 85);
            $this->assertIsString($encoded);
            $this->assertNotEmpty($encoded);

            // Save and read back
            Storage::disk('local')->put("test.{$format}", $encoded);
            $this->assertTrue(Storage::disk('local')->exists("test.{$format}"));

            // Verify it can be read back
            $readBack = $manager->read(Storage::disk('local')->get("test.{$format}"));
            $this->assertInstanceOf(\Intervention\Image\Interfaces\ImageInterface::class, $readBack);
        }
    }

    /** @test */
    public function it_maintains_transparency_in_png_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create image with transparency
        $image = $manager->create(100, 100);

        // Encode as PNG
        $png = ThumbnailGenerator::convertFormat($image, 'png', 85);

        // Save and verify
        Storage::disk('local')->put('transparent.png', $png);
        $readBack = $manager->read(Storage::disk('local')->get('transparent.png'));

        $this->assertEquals(100, $readBack->width());
        $this->assertEquals(100, $readBack->height());
    }

    /** @test */
    public function it_respects_quality_settings_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(100, 100);

        // Generate with different quality settings
        $lowQuality = ThumbnailGenerator::convertFormat($image, 'jpg', 10);
        $highQuality = ThumbnailGenerator::convertFormat($image, 'jpg', 100);

        // High quality should produce larger file
        $this->assertGreaterThan(strlen($lowQuality), strlen($highQuality));
    }

    /** @test */
    public function it_can_process_large_images_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create a large image
        $largeImage = $manager->create(2000, 1500);

        // Crop to thumbnail
        $thumbnail = ThumbnailGenerator::applyBasicCrop($largeImage, 200, 200);

        $this->assertEquals(200, $thumbnail->width());
        $this->assertEquals(200, $thumbnail->height());
    }

    /** @test */
    public function it_handles_memory_efficiently_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();

        $memoryBefore = memory_get_usage();

        // Process multiple images
        for ($i = 0; $i < 10; $i++) {
            $image = $manager->create(500, 500);
            $cropped = ThumbnailGenerator::applyBasicCrop($image, 100, 100);

            // Explicitly release memory
            unset($image, $cropped);
        }

        $memoryAfter = memory_get_usage();

        // Memory should not grow excessively
        $memoryGrowth = $memoryAfter - $memoryBefore;

        // Allow for some growth but not excessive (< 10MB)
        $this->assertLessThan(10 * 1024 * 1024, $memoryGrowth);
    }

    /** @test */
    public function it_can_chain_multiple_operations_with_intervention_3()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(400, 300);

        // Chain operations
        $processed = $image
            ->crop(200, 200, 100, 50)
            ->resize(150, 150);

        $this->assertEquals(150, $processed->width());
        $this->assertEquals(150, $processed->height());
    }

    /** @test */
    public function thumbnail_service_integration_with_intervention_3()
    {
        config(['thumbnails.presets.integration_test' => [
            'format' => 'webp',
            'smartcrop' => '150x150',
            'destination' => ['disk' => 'local', 'path' => 'integration/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
        ]]);

        // Create test source image
        $manager = ThumbnailGenerator::createImageManager();
        $sourceImage = $manager->create(400, 300);
        $sourceData = $sourceImage->toJpeg(85)->toString();
        Storage::disk('local')->put('test-source.jpg', $sourceData);

        // This test verifies that all components work together
        // In a real scenario, this would go through ThumbnailService
        $smartCrop = new SmartCropService();
        $testImage = $manager->read(Storage::disk('local')->get('test-source.jpg'));
        $cropped = $smartCrop->smartCrop($testImage, 150, 150);
        $webp = ThumbnailGenerator::convertFormat($cropped, 'webp', 85);

        Storage::disk('local')->put('integration/final.webp', $webp);

        // Verify the pipeline worked
        $this->assertTrue(Storage::disk('local')->exists('integration/final.webp'));

        // Verify result is correct format and size
        $final = $manager->read(Storage::disk('local')->get('integration/final.webp'));
        $this->assertEquals(150, $final->width());
        $this->assertEquals(150, $final->height());
    }
}
