<?php

namespace Askancy\LaravelSmartThumbnails\Support;

/**
 * Pixel Budget
 * Stops an image with too many pixels from reaching the decoder.
 *
 * The decoder allocates width x height x 4 bytes for the bitmap, no matter how
 * small the compressed file is: a few MB of PNG can ask for half a gigabyte of
 * RAM and kill the request with "Allowed memory size exhausted". The file size
 * tells you nothing about it — that is what makes a decompression bomb work.
 *
 * getimagesize() reads only the header, so the real pixel count is known
 * before anything is allocated.
 */
class PixelBudget
{
    /**
     * Default budget in megapixels. 60 needs roughly 240 MB of RAM.
     */
    public const DEFAULT_MEGAPIXELS = 60.0;

    /**
     * Megapixels of the given image, or null when the header cannot be read.
     */
    public static function megapixels(string $binary): ?float
    {
        if ($binary === '') {
            return null;
        }

        $size = @getimagesizefromstring($binary);

        if ($size === false || !isset($size[0], $size[1])) {
            return null;
        }

        return ((int) $size[0] * (int) $size[1]) / 1000000;
    }

    /**
     * True when the image has more pixels than the budget allows.
     *
     * Two deliberate holes: a budget of zero or less disables the guard, and
     * anything that cannot be measured goes through. The guard covers one
     * failure mode and must not become a second one — an unreadable file will
     * fail in the decoder, with the decoder's own error.
     */
    public static function exceeds(string $binary, float $budgetMegapixels): bool
    {
        if ($budgetMegapixels <= 0) {
            return false;
        }

        $megapixels = self::megapixels($binary);

        return $megapixels !== null && $megapixels > $budgetMegapixels;
    }

    /**
     * The budget from config, in megapixels.
     */
    public static function configuredBudget(): float
    {
        $configured = config('thumbnails.max_megapixels', self::DEFAULT_MEGAPIXELS);

        return $configured === null ? self::DEFAULT_MEGAPIXELS : (float) $configured;
    }
}
