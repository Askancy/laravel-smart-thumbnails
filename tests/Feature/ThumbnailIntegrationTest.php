<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Facades\Thumbnail;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic as Image;

class ThumbnailIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Crea una directory di test
        Storage::fake('local');

        // Crea un'immagine di test
        $testImage = Image::canvas(300, 200, '#ff0000');
        Storage::disk('local')->put('test-image.jpg', $testImage->encode('jpg')->getContents());
    }

    public function test_can_generate_thumbnail()
    {
        $url = Thumbnail::set('test')
            ->src('test-image.jpg', 'local')
            ->url();

        $this->assertNotEmpty($url);
        $this->assertStringContains('test-image_100_100.jpg', $url);
    }

    public function test_thumbnail_file_is_created()
    {
        Thumbnail::set('test')
            ->src('test-image.jpg', 'local')
            ->url();

        // Verifica che il file thumbnail sia stato creato
        $this->assertTrue(Storage::disk('local')->exists('test-crops/test-image_100_100.jpg'));
    }

    public function test_thumbnail_is_cached()
    {
        // Prima chiamata - genera il thumbnail
        $url1 = Thumbnail::set('test')
            ->src('test-image.jpg', 'local')
            ->url();

        $firstGenerationTime = Storage::disk('local')->lastModified('test-crops/test-image_100_100.jpg');

        // Aspetta un secondo
        sleep(1);

        // Seconda chiamata - dovrebbe usare la cache
        $url2 = Thumbnail::set('test')
            ->src('test-image.jpg', 'local')
            ->url();

        $secondGenerationTime = Storage::disk('local')->lastModified('test-crops/test-image_100_100.jpg');

        $this->assertEquals($url1, $url2);
        $this->assertEquals($firstGenerationTime, $secondGenerationTime);
    }

    public function test_can_handle_missing_source_image()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Source image not found');

        Thumbnail::set('test')
            ->src('non-existent.jpg', 'local')
            ->url();
    }

    public function test_purge_functionality()
    {
        // Genera alcuni thumbnail
        Thumbnail::set('test')->src('test-image.jpg', 'local')->url();

        // Verifica che esistano
        $this->assertTrue(Storage::disk('local')->exists('test-crops/test-image_100_100.jpg'));

        // Purge
        $purgedCount = Thumbnail::purgePreset('test');

        $this->assertGreaterThan(0, $purgedCount);
        $this->assertFalse(Storage::disk('local')->exists('test-crops/test-image_100_100.jpg'));
    }
}
