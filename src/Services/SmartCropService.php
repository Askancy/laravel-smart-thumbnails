<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Askancy\LaravelSmartThumbnails\Support\ImageSample;
use Intervention\Image\Interfaces\ImageInterface;

/** Content-aware cropping using edges and color contrast on a bounded sample. */
class SmartCropService
{
    public function smartCrop(ImageInterface $image, int $targetWidth, int $targetHeight): ImageInterface
    {
        if ($targetWidth <= 0 || $targetHeight <= 0) {
            throw new \InvalidArgumentException('Crop dimensions must be positive.');
        }
        $width = $image->width();
        $height = $image->height();
        $scale = min($width / $targetWidth, $height / $targetHeight);
        $cropWidth = max(1, min($width, (int) round($targetWidth * $scale)));
        $cropHeight = max(1, min($height, (int) round($targetHeight * $scale)));
        if ($cropWidth === $width && $cropHeight === $height) {
            return $image->resize($targetWidth, $targetHeight);
        }
        $sample = ImageSample::read($image);
        $horizontal = $cropWidth < $width;
        $length = $horizontal ? $sample['width'] : $sample['height'];
        $window = max(1, min($length, (int) round($length * ($horizontal ? $cropWidth / $width : $cropHeight / $height))));
        $offset = $this->bestWindow($this->axisEnergy($sample, $horizontal), $window);
        $available = $horizontal ? $width - $cropWidth : $height - $cropHeight;
        // Include both source edges when mapping sample coordinates back to the original.
        $position = $length === $window ? (int) round($available / 2)
            : (int) round($offset / ($length - $window) * $available);

        return $image->crop($cropWidth, $cropHeight, $horizontal ? $position : 0, $horizontal ? 0 : $position)
            ->resize($targetWidth, $targetHeight);
    }

    private function axisEnergy(array $sample, bool $horizontal): array
    {
        ['width' => $width, 'height' => $height, 'pixels' => $pixels] = $sample;
        $mean = [0.0, 0.0, 0.0];
        $weight = 0.0;
        foreach ($pixels as $pixel) {
            $weight += $pixel[3];
            for ($c = 0; $c < 3; $c++) {
                $mean[$c] += $pixel[$c] * $pixel[3];
            }
        }
        foreach ($mean as &$channel) {
            $channel /= max(1, $weight);
        }
        unset($channel);
        $energy = array_fill(0, $horizontal ? $width : $height, 0.0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $pixels[$y * $width + $x];
                $left = $pixels[$y * $width + max(0, $x - 1)];
                $right = $pixels[$y * $width + min($width - 1, $x + 1)];
                $top = $pixels[max(0, $y - 1) * $width + $x];
                $bottom = $pixels[min($height - 1, $y + 1) * $width + $x];
                $value = 0.0;
                for ($c = 0; $c < 3; $c++) {
                    $value += abs($left[$c] - $right[$c]) * min($left[3], $right[3]);
                    $value += abs($top[$c] - $bottom[$c]) * min($top[3], $bottom[3]);
                    $value += 0.2 * abs($pixel[$c] - $mean[$c]);
                }
                $energy[$horizontal ? $x : $y] += $value * $pixel[3];
            }
        }

        return $energy;
    }

    private function bestWindow(array $energy, int $window): int
    {
        $available = count($energy) - $window;
        $best = (int) round($available / 2);
        $bestScore = -1.0;
        for ($start = 0; $start <= $available; $start++) {
            $score = 0.0;
            for ($i = 0; $i < $window; $i++) {
                // Keep detail away from the cut edges; prefer the center on tied scores.
                $importance = 1 - 0.5 * abs(2 * ($i + 0.5) / $window - 1);
                $score += $energy[$start + $i] * $importance;
            }
            if ($score > $bestScore + 0.000001 || (abs($score - $bestScore) <= 0.000001
                && abs($start - $available / 2) < abs($best - $available / 2))) {
                $best = $start;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
