<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Askancy\LaravelSmartThumbnails\Support\ImageSample;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/** Native PHP background fitting inspired by dont-crop; not a JavaScript port. */
class DontCropService
{
    public function contain(ImageInterface $image, int $width, int $height, string $background = 'gradient', bool $allowUpscale = true): ImageInterface
    {
        if ($width <= 0 || $height <= 0) {
            throw new \InvalidArgumentException('Canvas dimensions must be positive.');
        }
        $manager = new ImageManager($image->driver());
        $vertical = $image->width() / $image->height() >= $width / $height;
        if ($background === 'gradient') {
            $gradient = $this->fitGradient($image, $vertical ? 'vertical' : 'horizontal');
            $strip = imagecreatetruecolor($vertical ? 1 : 128, $vertical ? 128 : 1);
            $start = sscanf($gradient['start'], '#%02x%02x%02x');
            $end = sscanf($gradient['end'], '#%02x%02x%02x');
            for ($i = 0; $i < 128; $i++) {
                $rgb = [];
                for ($c = 0; $c < 3; $c++) {
                    $rgb[] = (int) round($start[$c] + ($end[$c] - $start[$c]) * $i / 127);
                }
                imagesetpixel($strip, $vertical ? 0 : $i, $vertical ? $i : 0, imagecolorallocate($strip, ...$rgb));
            }
            ob_start();
            imagepng($strip);
            $binary = (string) ob_get_clean();
            $canvas = $manager->read($binary)->resize($width, $height);
        } else {
            $color = $background === 'dominant' ? $this->getPalette($image, 1)[0] : $background;
            $canvas = $manager->create($width, $height)->fill($color);
        }
        $foreground = clone $image;
        $factor = min($width / $image->width(), $height / $image->height());
        if (!$allowUpscale) {
            $factor = min(1, $factor);
        }
        $foreground->resize(max(1, (int) round($image->width() * $factor)), max(1, (int) round($image->height() * $factor)));

        return $canvas->place($foreground, 'center');
    }

    /** Fit RGB endpoints by opacity-weighted linear regression along the padding axis. */
    public function fitGradient(ImageInterface $image, string $direction = 'vertical'): array
    {
        if (!in_array($direction, ['vertical', 'horizontal'], true)) {
            throw new \InvalidArgumentException('Gradient direction must be vertical or horizontal.');
        }
        $sample = ImageSample::read($image);
        $vertical = $direction === 'vertical';
        $length = $vertical ? $sample['height'] : $sample['width'];
        $s = $st = $stt = 0.0;
        $colors = $products = [0.0, 0.0, 0.0];
        foreach ($sample['pixels'] as $i => $pixel) {
            $t = ($vertical ? intdiv($i, $sample['width']) : $i % $sample['width']) / max(1, $length - 1);
            $a = $pixel[3];
            $s += $a;
            $st += $t * $a;
            $stt += $t * $t * $a;
            for ($c = 0; $c < 3; $c++) {
                $colors[$c] += $pixel[$c] * $a;
                $products[$c] += $pixel[$c] * $t * $a;
            }
        }
        $start = $end = [];
        for ($c = 0; $c < 3; $c++) {
            $denominator = $s * $stt - $st * $st;
            $slope = abs($denominator) > 0.000001 ? ($s * $products[$c] - $st * $colors[$c]) / $denominator : 0;
            $intercept = $s > 0 ? ($colors[$c] - $slope * $st) / $s : 255;
            $start[] = $intercept;
            $end[] = $intercept + $slope;
        }

        return ['start' => $this->hex($start), 'end' => $this->hex($end), 'direction' => $direction];
    }

    /** Dominant RGB colors from an opacity-weighted histogram, most common first. */
    public function getPalette(ImageInterface $image, int $count = 5): array
    {
        if ($count < 1 || $count > 16) {
            throw new \InvalidArgumentException('Palette size must be between 1 and 16.');
        }
        $bins = [];
        foreach (ImageSample::read($image)['pixels'] as $pixel) {
            if ($pixel[3] <= 0) {
                continue;
            }
            $key = intdiv($pixel[0], 32) . ':' . intdiv($pixel[1], 32) . ':' . intdiv($pixel[2], 32);
            $bins[$key] ??= [0.0, 0.0, 0.0, 0.0];
            for ($c = 0; $c < 3; $c++) {
                $bins[$key][$c] += $pixel[$c] * $pixel[3];
            }
            $bins[$key][3] += $pixel[3];
        }
        uasort($bins, fn ($a, $b) => $b[3] <=> $a[3]);
        $palette = [];
        foreach (array_slice($bins, 0, $count) as $bin) {
            $palette[] = $this->hex([$bin[0] / $bin[3], $bin[1] / $bin[3], $bin[2] / $bin[3]]);
        }

        return $palette ?: ['#ffffff'];
    }

    private function hex(array $channels): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(fn ($value) => max(0, min(255, (int) round($value))), $channels));
    }
}
