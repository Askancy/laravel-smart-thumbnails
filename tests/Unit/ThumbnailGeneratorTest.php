<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;

class ThumbnailGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_creates_image_manager_with_gd_driver()
    {
        config(['thumbnails.intervention_driver' => 'gd']);

        $manager = ThumbnailGenerator::createImageManager();

        $this->assertInstanceOf(ImageManager::class, $manager);
    }

    /** @test */
    public function it_creates_image_manager_with_imagick_driver_if_available()
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ImageMagick extension not available');
        }

        config(['thumbnails.intervention_driver' => 'imagick']);

        $manager = ThumbnailGenerator::createImageManager();

        $this->assertInstanceOf(ImageManager::class, $manager);
    }

    /** @test */
    public function it_applies_basic_crop_to_landscape_image()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create a test image (landscape: 400x200)
        $image = $manager->create(400, 200);

        // Crop to square (100x100)
        $cropped = ThumbnailGenerator::applyBasicCrop($image, 100, 100);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function it_applies_basic_crop_to_portrait_image()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create a test image (portrait: 200x400)
        $image = $manager->create(200, 400);

        // Crop to square (100x100)
        $cropped = ThumbnailGenerator::applyBasicCrop($image, 100, 100);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function it_does_not_crop_if_dimensions_match()
    {
        $manager = ThumbnailGenerator::createImageManager();

        // Create a test image (100x100)
        $image = $manager->create(100, 100);

        // Crop to same size
        $cropped = ThumbnailGenerator::applyBasicCrop($image, 100, 100);

        $this->assertEquals(100, $cropped->width());
        $this->assertEquals(100, $cropped->height());
    }

    /** @test */
    public function it_converts_image_to_webp_format()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(100, 100);

        $webp = ThumbnailGenerator::convertFormat($image, 'webp', 85);

        $this->assertIsString($webp);
        $this->assertNotEmpty($webp);
    }

    /** @test */
    public function it_converts_image_to_jpg_format()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(100, 100);

        $jpg = ThumbnailGenerator::convertFormat($image, 'jpg', 85);

        $this->assertIsString($jpg);
        $this->assertNotEmpty($jpg);
    }

    /** @test */
    public function it_converts_image_to_png_format()
    {
        $manager = ThumbnailGenerator::createImageManager();
        $image = $manager->create(100, 100);

        $png = ThumbnailGenerator::convertFormat($image, 'png', 85);

        $this->assertIsString($png);
        $this->assertNotEmpty($png);
    }

    /** @test */
    public function it_formats_bytes_correctly()
    {
        $this->assertEquals('0 B', ThumbnailGenerator::formatBytes(0));
        $this->assertEquals('1 KB', ThumbnailGenerator::formatBytes(1024));
        $this->assertEquals('1 MB', ThumbnailGenerator::formatBytes(1024 * 1024));
        $this->assertEquals('1 GB', ThumbnailGenerator::formatBytes(1024 * 1024 * 1024));
        $this->assertEquals('500 B', ThumbnailGenerator::formatBytes(500));
        $this->assertEquals('1.5 KB', ThumbnailGenerator::formatBytes(1536));
    }

    /** @test */
    public function it_sanitizes_filenames()
    {
        $this->assertEquals('test', ThumbnailGenerator::sanitizeFilename('test'));
        $this->assertEquals('test123', ThumbnailGenerator::sanitizeFilename('test123'));
        $this->assertEquals('test-file', ThumbnailGenerator::sanitizeFilename('test-file'));
        $this->assertEquals('test_file', ThumbnailGenerator::sanitizeFilename('test_file'));

        // Should remove special characters
        $this->assertEquals('testfile', ThumbnailGenerator::sanitizeFilename('test@#$%file'));
        $this->assertEquals('testfile', ThumbnailGenerator::sanitizeFilename('test file'));
        $this->assertEquals('testfile', ThumbnailGenerator::sanitizeFilename('test.file'));
    }

    /** @test */
    public function it_truncates_long_filenames()
    {
        $longFilename = str_repeat('a', 150);
        $sanitized = ThumbnailGenerator::sanitizeFilename($longFilename);

        $this->assertEquals(100, strlen($sanitized));
    }

    /** @test */
    public function it_validates_image_content_with_allowed_extensions()
    {
        config(['thumbnails.validate_image_content' => true]);
        config(['thumbnails.allowed_extensions' => ['jpg', 'png', 'webp']]);

        // Should not throw
        ThumbnailGenerator::validateImageContent('test.jpg');
        ThumbnailGenerator::validateImageContent('test.png');
        ThumbnailGenerator::validateImageContent('test.webp');

        $this->assertTrue(true); // If we get here, validation passed
    }

    /** @test */
    public function it_throws_exception_for_invalid_extensions()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not allowed');

        config(['thumbnails.validate_image_content' => true]);
        config(['thumbnails.allowed_extensions' => ['jpg', 'png']]);

        ThumbnailGenerator::validateImageContent('test.exe');
    }

    /** @test */
    public function it_skips_validation_when_disabled()
    {
        config(['thumbnails.validate_image_content' => false]);

        // Should not throw even with invalid extension
        ThumbnailGenerator::validateImageContent('test.exe');

        $this->assertTrue(true);
    }

    /** @test */
    public function it_generates_hash_prefix_subdirectory()
    {
        $config = ['subdirectory_strategy' => 'hash_prefix'];

        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Should be in format: a/b/
        $this->assertMatchesRegularExpression('/^[a-f0-9]\/[a-f0-9]\/$/', $subdir);
        $this->assertEquals(4, strlen($subdir)); // X/Y/ = 4 characters
    }

    /** @test */
    public function it_generates_consistent_hash_subdirectories()
    {
        $config = ['subdirectory_strategy' => 'hash_prefix'];

        $subdir1 = ThumbnailGenerator::generateSubdirectory('testfile', $config);
        $subdir2 = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Same filename should always generate same subdirectory
        $this->assertEquals($subdir1, $subdir2);
    }

    /** @test */
    public function it_generates_filename_prefix_subdirectory()
    {
        $config = ['subdirectory_strategy' => 'filename_prefix'];

        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Should be based on filename characters
        $this->assertMatchesRegularExpression('/^[a-z0-9]\/[a-z0-9]\/$/', $subdir);
    }

    /** @test */
    public function it_generates_misc_subdirectory_for_short_filenames()
    {
        $config = ['subdirectory_strategy' => 'filename_prefix'];

        $subdir = ThumbnailGenerator::generateSubdirectory('a', $config);

        $this->assertEquals('misc/', $subdir);
    }

    /** @test */
    public function it_generates_hash_levels_subdirectory()
    {
        $config = ['subdirectory_strategy' => 'hash_levels'];

        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Should be in format: a/b/c/
        $this->assertMatchesRegularExpression('/^[a-f0-9]\/[a-f0-9]\/[a-f0-9]\/$/', $subdir);
        $this->assertEquals(6, strlen($subdir)); // X/Y/Z/ = 6 characters
    }

    /** @test */
    public function it_generates_date_based_subdirectory()
    {
        $config = ['subdirectory_strategy' => 'date_based'];

        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Should be in format: YYYY/MM/DD/
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}\/$/', $subdir);
    }

    /** @test */
    public function it_generates_no_subdirectory_when_strategy_is_none()
    {
        $config = ['subdirectory_strategy' => 'none'];

        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        $this->assertEquals('', $subdir);
    }

    /** @test */
    public function it_uses_default_strategy_when_not_specified()
    {
        config(['thumbnails.default_subdirectory_strategy' => 'hash_prefix']);

        $config = [];
        $subdir = ThumbnailGenerator::generateSubdirectory('testfile', $config);

        // Should use hash_prefix as default
        $this->assertMatchesRegularExpression('/^[a-f0-9]\/[a-f0-9]\/$/', $subdir);
    }

    /** @test */
    public function it_normalizes_paths_correctly()
    {
        $this->assertEquals('test/path', ThumbnailGenerator::normalizePath('test/path'));
        $this->assertEquals('test/path', ThumbnailGenerator::normalizePath('/test/path/'));
        $this->assertEquals('test/path', ThumbnailGenerator::normalizePath('//test//path//'));
        $this->assertEquals('', ThumbnailGenerator::normalizePath('/'));
        $this->assertEquals('', ThumbnailGenerator::normalizePath(''));
    }

    /** @test */
    public function it_checks_if_disk_supports_visibility()
    {
        // S3 should support visibility
        config(['filesystems.disks.s3' => ['driver' => 's3']]);
        $this->assertTrue(ThumbnailGenerator::diskSupportsVisibility('s3'));

        // GCS should support visibility
        config(['filesystems.disks.gcs' => ['driver' => 'gcs']]);
        $this->assertTrue(ThumbnailGenerator::diskSupportsVisibility('gcs'));

        // Local should support visibility
        config(['filesystems.disks.local' => ['driver' => 'local']]);
        $this->assertTrue(ThumbnailGenerator::diskSupportsVisibility('local'));
    }

    /** @test */
    public function it_returns_false_for_non_existent_disk()
    {
        $this->assertFalse(ThumbnailGenerator::diskSupportsVisibility('non_existent_disk'));
    }

    /** @test */
    public function it_creates_destination_directory()
    {
        $disk = 'local';
        $directory = 'test/thumbnails';

        ThumbnailGenerator::ensureDestinationDirectory($disk, $directory);

        $this->assertTrue(Storage::disk($disk)->exists($directory));
    }

    /** @test */
    public function it_handles_nested_directory_creation()
    {
        $disk = 'local';
        $directory = 'test/very/nested/thumbnails';

        ThumbnailGenerator::ensureDestinationDirectory($disk, $directory);

        $this->assertTrue(Storage::disk($disk)->exists($directory));
    }

    /** @test */
    public function it_does_not_fail_if_directory_already_exists()
    {
        $disk = 'local';
        $directory = 'test/existing';

        Storage::disk($disk)->makeDirectory($directory);

        // Should not throw
        ThumbnailGenerator::ensureDestinationDirectory($disk, $directory);

        $this->assertTrue(Storage::disk($disk)->exists($directory));
    }

    /** @test */
    public function it_sets_public_visibility_if_supported()
    {
        $disk = 'local';
        $path = 'test/file.jpg';

        Storage::disk($disk)->put($path, 'content');

        // Should not throw
        ThumbnailGenerator::setPublicVisibility($disk, $path);

        $this->assertTrue(true); // If we get here, it worked
    }

    /** @test */
    public function it_handles_visibility_errors_gracefully()
    {
        config(['thumbnails.log_errors' => false]);

        $disk = 'non_existent';
        $path = 'test/file.jpg';

        // Should not throw even if disk doesn't exist
        ThumbnailGenerator::setPublicVisibility($disk, $path);

        $this->assertTrue(true);
    }
}
