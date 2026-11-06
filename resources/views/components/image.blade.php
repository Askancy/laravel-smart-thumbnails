<img
    src="{{ $image['url'] }}"
    alt="{{ $alt }}"
    @if ($srcset !== '') srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
    @if ($image['width'] !== null) width="{{ $image['width'] }}" height="{{ $image['height'] }}" @endif
    {{ $attributes->except(['src', 'srcset', 'sizes', 'alt', 'width', 'height'])->merge(['loading' => 'lazy', 'decoding' => 'async']) }}
>
