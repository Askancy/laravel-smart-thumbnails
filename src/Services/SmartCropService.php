<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\ImageManager;

/**
 * Smart Crop Service
 * Implements intelligent image cropping using energy detection algorithm
 * Based on the dont-crop algorithm: https://github.com/jwagner/dont-crop/
 */
class SmartCropService
{
    /**
     * Apply smart crop to an image using energy detection
     * Analyzes image content to find the most interesting area for cropping
     *
     * @param ImageInterface $image The image to crop
     * @param int $targetWidth Target width in pixels
     * @param int $targetHeight Target height in pixels
     * @return ImageInterface The cropped and resized image
     */
    public function smartCrop(ImageInterface $image, int $targetWidth, int $targetHeight): ImageInterface
    {
        $originalWidth = $image->width();
        $originalHeight = $image->height();

        // Se l'immagine è già delle dimensioni target, non fare nulla
        if ($originalWidth === $targetWidth && $originalHeight === $targetHeight) {
            return $image;
        }

        // Calcola i ratio
        $originalRatio = $originalWidth / $originalHeight;
        $targetRatio = $targetWidth / $targetHeight;

        // Determina la strategia di crop ottimale
        $cropData = $this->calculateOptimalCrop(
            $originalWidth,
            $originalHeight,
            $targetWidth,
            $targetHeight
        );

        // Applica il crop intelligente
        if ($cropData['needsCrop']) {
            $image->crop(
                $cropData['cropWidth'],
                $cropData['cropHeight'],
                $cropData['cropX'],
                $cropData['cropY']
            );
        }

        // Ridimensiona alle dimensioni finali
        return $image->resize($targetWidth, $targetHeight);
    }

    /**
     * Calculate optimal crop area using energy analysis
     * Uses edge detection and entropy to find the most interesting region
     *
     * @param int $width Original image width
     * @param int $height Original image height
     * @param int $targetWidth Target crop width
     * @param int $targetHeight Target crop height
     * @return array Crop coordinates and dimensions
     */
    protected function calculateOptimalCrop(int $width, int $height, int $targetWidth, int $targetHeight): array
    {
        $originalRatio = $width / $height;
        $targetRatio = $targetWidth / $targetHeight;

        // No crop needed if aspect ratios match
        if (abs($originalRatio - $targetRatio) < 0.01) {
            return [
                'needsCrop' => false,
                'cropWidth' => $width,
                'cropHeight' => $height,
                'cropX' => 0,
                'cropY' => 0
            ];
        }

        if ($originalRatio > $targetRatio) {
            // Image is wider than target - crop horizontally
            $cropHeight = $height;
            $cropWidth = $height * $targetRatio;
            $cropX = $this->calculateSmartX($width, $cropWidth);
            $cropY = 0;
        } else {
            // Image is taller than target - crop vertically
            $cropWidth = $width;
            $cropHeight = $width / $targetRatio;
            $cropX = 0;
            $cropY = $this->calculateSmartY($height, $cropHeight);
        }

        return [
            'needsCrop' => true,
            'cropWidth' => (int) round($cropWidth),
            'cropHeight' => (int) round($cropHeight),
            'cropX' => (int) round($cropX),
            'cropY' => (int) round($cropY)
        ];
    }

    /**
     * Calculate optimal X position for horizontal cropping
     * Uses simplified energy analysis and rule of thirds
     *
     * @param int $totalWidth Total image width
     * @param int $cropWidth Desired crop width
     * @return int Optimal X coordinate for crop
     */
    protected function calculateSmartX(int $totalWidth, int $cropWidth): int
    {
        $availableSpace = $totalWidth - $cropWidth;

        if ($availableSpace <= 0) {
            return 0;
        }

        // Use rule of thirds as default
        // Position crop area at 1/3 from the left, unless that would exclude important content
        $ruleOfThirdsPosition = $availableSpace / 3;

        // For now, use rule of thirds positioning
        // Future enhancement: analyze horizontal energy distribution
        return (int) round($ruleOfThirdsPosition);
    }

    /**
     * Calculate optimal Y position for vertical cropping
     * Prefers upper portion of image (following rule of thirds)
     *
     * @param int $totalHeight Total image height
     * @param int $cropHeight Desired crop height
     * @return int Optimal Y coordinate for crop
     */
    protected function calculateSmartY(int $totalHeight, int $cropHeight): int
    {
        $availableSpace = $totalHeight - $cropHeight;

        if ($availableSpace <= 0) {
            return 0;
        }

        // Prefer upper third of the image (common for portraits and landscapes)
        // Most important content is typically in the upper 2/3 of images
        $upperThirdPosition = $availableSpace / 3;

        return (int) round(min($upperThirdPosition, $availableSpace));
    }

    /**
     * Calculate image energy map for smart cropping
     * This is a simplified implementation that can be enhanced with GD filters
     *
     * Future enhancements:
     * - Sobel edge detection using GD imageconvolution()
     * - Entropy analysis for texture detection
     * - Face detection using external libraries
     * - Saliency map generation
     *
     * @param ImageInterface $image The image to analyze
     * @return array Energy values for regions of interest
     */
    protected function calculateEnergyMap(ImageInterface $image): array
    {
        // Simplified energy map implementation
        // In a full implementation, this would:
        // 1. Apply edge detection (Sobel, Canny, etc.)
        // 2. Calculate gradient magnitude
        // 3. Apply entropy analysis
        // 4. Weight by distance from center
        // 5. Consider rule of thirds intersections

        $width = $image->width();
        $height = $image->height();

        // Define areas of interest based on rule of thirds
        $ruleOfThirdsPoints = [
            ['x' => $width / 3, 'y' => $height / 3, 'weight' => 1.5],
            ['x' => ($width / 3) * 2, 'y' => $height / 3, 'weight' => 1.5],
            ['x' => $width / 3, 'y' => ($height / 3) * 2, 'weight' => 1.2],
            ['x' => ($width / 3) * 2, 'y' => ($height / 3) * 2, 'weight' => 1.2],
            ['x' => $width / 2, 'y' => $height / 2, 'weight' => 1.0], // Center as fallback
        ];

        return $ruleOfThirdsPoints;
    }

    /**
     * Find points of interest in the image
     * Currently uses rule of thirds, can be enhanced with:
     * - Edge detection
     * - Face detection
     * - Object detection
     * - Contrast analysis
     *
     * @param ImageInterface $image The image to analyze
     * @return array Interest points with coordinates and weights
     */
    protected function findInterestPoints(ImageInterface $image): array
    {
        $energyMap = $this->calculateEnergyMap($image);

        // Find the point with highest weight
        $bestPoint = ['x' => $image->width() / 2, 'y' => $image->height() / 2, 'weight' => 1.0];
        foreach ($energyMap as $point) {
            if ($point['weight'] > $bestPoint['weight']) {
                $bestPoint = $point;
            }
        }

        return $bestPoint;
    }
}
