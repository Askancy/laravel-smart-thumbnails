<?php

namespace Askancy\LaravelSmartThumbnails\Services;

use Intervention\Image\Image;

class SmartCropService
{
    /**
     * Applica smart crop usando l'algoritmo dont-crop
     * Basato su: https://github.com/jwagner/dont-crop/
     */
    public function smartCrop(Image $image, int $targetWidth, int $targetHeight): Image
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
     * Calcola il crop ottimale usando l'algoritmo smart crop
     */
    protected function calculateOptimalCrop(int $width, int $height, int $targetWidth, int $targetHeight): array
    {
        $originalRatio = $width / $height;
        $targetRatio = $targetWidth / $targetHeight;

        // Se i ratio sono uguali, non serve crop
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
            // Immagine più larga del target - crop orizzontalmente
            $cropHeight = $height;
            $cropWidth = $height * $targetRatio;
            $cropX = $this->calculateSmartX($width, $cropWidth);
            $cropY = 0;
        } else {
            // Immagine più alta del target - crop verticalmente
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
     * Calcola la posizione X ottimale per il crop (smart crop orizzontale)
     * Implementa una versione semplificata dell'algoritmo dont-crop
     */
    protected function calculateSmartX(int $totalWidth, int $cropWidth): int
    {
        $availableSpace = $totalWidth - $cropWidth;

        if ($availableSpace <= 0) {
            return 0;
        }

        // Per ora usa il centro, ma qui si può implementare
        // il rilevamento del soggetto dell'immagine
        return $availableSpace / 2;
    }

    /**
     * Calcola la posizione Y ottimale per il crop (smart crop verticale)
     */
    protected function calculateSmartY(int $totalHeight, int $cropHeight): int
    {
        $availableSpace = $totalHeight - $cropHeight;

        if ($availableSpace <= 0) {
            return 0;
        }

        // Preferisce la parte superiore dell'immagine (regola dei terzi)
        // Invece del centro, usa 1/3 dall'alto
        return min($availableSpace / 3, $availableSpace);
    }

    /**
     * Analizza l'immagine per trovare punti di interesse
     * (Implementazione futura per smart crop avanzato)
     */
    protected function findInterestPoints(Image $image): array
    {
        // TODO: Implementare rilevamento bordi, contrasto, volti, etc.
        // Per ora ritorna il centro
        return [
            'x' => $image->width() / 2,
            'y' => $image->height() / 2,
            'weight' => 1.0
        ];
    }
}
