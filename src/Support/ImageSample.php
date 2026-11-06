<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Intervention\Image\Interfaces\ImageInterface;

/** Bounded RGB samples shared by content analysis and background fitting. */
class ImageSample
{
    public static function read(ImageInterface $image, int $limit = 96): array
    {
        $native = $image->core()->native();
        if ($native instanceof \GdImage) {
            // Resample directly to avoid cloning a full-resolution GD bitmap.
            $factor = min(1, $limit / $image->width(), $limit / $image->height());
            $width = max(1, (int) round($image->width() * $factor));
            $height = max(1, (int) round($image->height() * $factor));
            $bitmap = imagecreatetruecolor($width, $height);
            imagealphablending($bitmap, false);
            imagesavealpha($bitmap, true);
            imagecopyresampled($bitmap, $native, 0, 0, 0, 0, $width, $height, $image->width(), $image->height());
        } else {
            $sample = clone $image;
            $sample->scaleDown(width: $limit, height: $limit);
            $bitmap = imagecreatefromstring($sample->toPng()->toString());
        }
        if ($bitmap === false) {
            throw new \RuntimeException('Cannot sample the source image.');
        }
        $width = imagesx($bitmap);
        $height = imagesy($bitmap);
        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorsforindex($bitmap, imagecolorat($bitmap, $x, $y));
                $pixels[] = [$color['red'], $color['green'], $color['blue'], 1 - $color['alpha'] / 127];
            }
        }

        return ['width' => $width, 'height' => $height, 'pixels' => $pixels];
    }
}
