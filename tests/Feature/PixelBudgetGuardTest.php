<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Facades\Thumbnail;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

/**
 * The bomb is not the file size, it is the pixel count: the decoder allocates
 * width x height x 4 bytes and the request dies with "Allowed memory size
 * exhausted". A thumbnail is not worth a dead request, so an oversized source
 * is refused before the decoder sees it and the caller degrades to its
 * fallback.
 */
class PixelBudgetGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('big.png', $this->pngBinary(1000, 500)); // 0.5 MP
    }

    public function test_a_source_over_the_budget_is_never_decoded(): void
    {
        config(['thumbnails.max_megapixels' => 0.4]);

        $url = Thumbnail::set('test')->src('big.png', 'local')->urlSafe();

        $this->assertSame([], Storage::disk('local')->allFiles('test-crops'), 'No thumbnail should have been written.');
        $this->assertNotEmpty($url, 'The caller must still get something to render.');
    }

    public function test_a_source_within_the_budget_is_processed(): void
    {
        config(['thumbnails.max_megapixels' => 60]);

        Thumbnail::set('test')->src('big.png', 'local')->urlSafe();

        $this->assertNotSame([], Storage::disk('local')->allFiles('test-crops'), 'The thumbnail should have been written.');
    }

    private function pngBinary(int $width, int $height): string
    {
        $gd = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($gd);
        $binary = (string) ob_get_clean();

        imagedestroy($gd);

        return $binary;
    }
}
