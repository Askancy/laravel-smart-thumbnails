<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Configuration
    |--------------------------------------------------------------------------
    */
    'default_quality' => 85,
    'default_format' => 'webp',
    'enable_smart_crop' => true,
    'cache_thumbnails' => true,

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Configurations
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'news' => [
            'format' => 'webp',
            'smartcrop' => '130x130',
            'destination' => ['disk' => 'local', 'path' => 'crops/news/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
            'variants' => [
                'mobile' => ['smartcrop' => '80x80', 'quality' => 80],
                'desktop' => ['smartcrop' => '200x150', 'quality' => 90],
                'large' => ['smartcrop' => '400x300', 'quality' => 95],
            ]
        ],
        'articles' => [
            'format' => 'jpg',
            'smartcrop' => '200x150',
            'destination' => ['disk' => 'public', 'path' => 'crops/articles/'],
            'quality' => 90,
            'smart_crop_enabled' => true,
            'variants' => [
                'thumb' => ['smartcrop' => '100x75'],
                'hero' => ['smartcrop' => '800x400'],
            ]
        ],
        'gallery' => [
            'format' => 'webp',
            'smartcrop' => '300x200',
            'destination' => ['disk' => 'public', 'path' => 'crops/gallery/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
            'variants' => [
                'thumbnail' => ['smartcrop' => '150x150'],
                'preview' => ['smartcrop' => '500x300'],
            ]
        ],
        'profile' => [
            'format' => 'webp',
            'smartcrop' => '100x100',
            'destination' => ['disk' => 'public', 'path' => 'crops/profiles/'],
            'quality' => 80,
            'smart_crop_enabled' => true,
            'variants' => [
                'small' => ['smartcrop' => '50x50'],
                'medium' => ['smartcrop' => '150x150'],
                'large' => ['smartcrop' => '300x300'],
            ]
        ],
    ],
];
