<?php

namespace Askancy\LaravelSmartThumbnails\View\Components;

use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Illuminate\View\Component;

class ResponsiveImage extends Component
{
    public function __construct(
        public string $src,
        public string $preset = 'gallery',
        public string $disk = 'public',
        public array $variants = [],
        public string $sizes = '100vw',
        public string $alt = ''
    ) {
    }

    public function render()
    {
        // Isolate the fluent state from other facade calls/components in the same view.
        $service = clone app(ThumbnailService::class);
        $service->set($this->preset)->src($this->src, $this->disk)->strict();
        $main = null;
        $candidates = [];
        try {
            $main = $service->imageData();
            $candidates[$main['width']] = $main['url'];
            $variants = $this->variants ?: array_keys($service->getVariants());
            foreach ($variants as $variant) {
                try {
                    $image = $service->imageData($variant);
                    // Width descriptors must describe alternate resolutions of the same aspect ratio.
                    if (abs($image['width'] / $image['height'] - $main['width'] / $main['height']) < 0.01) {
                        $candidates[$image['width']] ??= $image['url'];
                    }
                } catch (\Exception $e) {
                    // An unavailable variant must not prevent rendering the main image.
                }
            }
        } catch (\Exception $e) {
            $main = ['url' => $service->urlSafe(), 'width' => null, 'height' => null];
        }
        ksort($candidates, SORT_NUMERIC);
        $srcset = [];
        foreach ($candidates as $width => $url) {
            $srcset[] = str_replace([' ', ','], ['%20', '%2C'], $url) . ' ' . $width . 'w';
        }

        return view('smart-thumbnails::components.image', [
            'image' => $main, 'srcset' => implode(', ', $srcset),
        ]);
    }
}
