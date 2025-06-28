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
    | Error Handling
    |--------------------------------------------------------------------------
    */
    'silent_mode_default' => false,          // Modalità silenziosa di default
    'generate_placeholders' => true,         // Genera placeholder in caso di errore
    'placeholder_url' => '/images/thumbnail-error.png', // URL placeholder personalizzato
    'log_errors' => true,                    // Log degli errori

    /*
    |--------------------------------------------------------------------------
    | Fallback Options
    |--------------------------------------------------------------------------
    */
    'fallback_to_original' => true,          // Usa immagine originale se thumbnail fallisce
    'placeholder_color' => '#f8f9fa',        // Colore placeholder generato
    'placeholder_text_color' => '#6c757d',   // Colore testo placeholder

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
            'silent_mode' => false,
            'variants' => [
                'mobile' => ['smartcrop' => '80x80', 'quality' => 80],
                'desktop' => ['smartcrop' => '200x150', 'quality' => 90],
                'large' => ['smartcrop' => '400x300', 'quality' => 95],
            ]
        ],
        'gallery' => [
            'format' => 'webp',
            'smartcrop' => '300x200',
            'destination' => ['disk' => 'public', 'path' => 'crops/gallery/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
            'silent_mode' => true,
            'variants' => [
                'thumbnail' => ['smartcrop' => '150x150'],
                'preview' => ['smartcrop' => '500x300'],
            ]
        ],
    ],
];
