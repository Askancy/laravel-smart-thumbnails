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
    | Subdirectory Strategy
    |--------------------------------------------------------------------------
    | Strategy for organizing thumbnails in subdirectories to optimize filesystem performance:
    | 
    | 'hash_prefix'    - a/b/ based on MD5 hash (recommended for high volume)
    | 'date_based'     - 2025/01/28/ based on creation date
    | 'filename_prefix'- ab/cd/ based on first filename characters
    | 'hash_levels'    - a/b/c/ multi-level for optimal distribution
    | 'none'           - no subdirectories (not recommended for >1000 files)
    */
    'default_subdirectory_strategy' => 'hash_prefix',

    /*
    |--------------------------------------------------------------------------
    | Error Handling & Safety
    |--------------------------------------------------------------------------
    */
    'silent_mode_default' => false,         // Default silent mode for error-safe operations
    'generate_placeholders' => true,        // Generate placeholder images on errors
    'placeholder_url' => '/images/thumbnail-error.png', // Custom placeholder URL
    'log_errors' => true,                   // Enable error logging

    /*
    |--------------------------------------------------------------------------
    | Fallback Options
    |--------------------------------------------------------------------------
    */
    'fallback_to_original' => true,         // Use original image if thumbnail fails
    'placeholder_color' => '#f8f9fa',       // Generated placeholder background color
    'placeholder_text_color' => '#6c757d',  // Generated placeholder text color

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    */
    'max_files_per_directory' => 1000,      // Maximum files per directory before subdirectory creation
    'auto_cleanup_empty_dirs' => true,      // Automatically clean empty directories
    'enable_webp_fallback' => true,         // Fallback to JPG if WebP not supported

    /*
    |--------------------------------------------------------------------------
    | Advanced Configuration
    |--------------------------------------------------------------------------
    */
    'intervention_driver' => 'gd',          // Intervention Image driver: 'gd' or 'imagick'
    'memory_limit' => '256M',               // Memory limit for image processing
    'timeout' => 30,                        // Processing timeout per image (seconds)
    'enable_progressive_jpeg' => true,      // Progressive JPEG for better loading
    'jpeg_optimize' => true,                // JPEG optimization
    'png_compression' => 6,                 // PNG compression level (0-9)
    'webp_lossless' => false,              // WebP lossless (larger files but perfect quality)

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Statistics
    |--------------------------------------------------------------------------
    */
    'enable_stats' => true,                 // Enable statistics collection
    'stats_retention_days' => 30,           // Statistics retention period
    'log_generation_time' => false,         // Log thumbnail generation time
    'monitor_disk_usage' => true,           // Monitor disk usage

    /*
    |--------------------------------------------------------------------------
    | Security Settings
    |--------------------------------------------------------------------------
    */
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'], // Allowed file extensions
    'max_file_size' => 10 * 1024 * 1024,   // Maximum file size (10MB)
    'validate_image_content' => true,       // Validate actual image content
    'sanitize_filenames' => true,           // Sanitize filenames for security

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Presets
    |--------------------------------------------------------------------------
    | Define your thumbnail configurations here. Each preset can have multiple
    | variants for responsive design and different use cases.
    */
    'presets' => [
        'gallery' => [
            'format' => 'webp',
            'smartcrop' => '300x200',
            'destination' => ['disk' => 'public', 'path' => 'thumbnails/gallery/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
            'silent_mode' => true,  // Error-safe for public galleries
            'subdirectory_strategy' => 'hash_prefix', // Optimal for large galleries
            'variants' => [
                'thumbnail' => ['smartcrop' => '150x150', 'quality' => 80],
                'medium' => ['smartcrop' => '400x300', 'quality' => 85],
                'large' => ['smartcrop' => '800x600', 'quality' => 90],
            ]
        ],

        'products' => [
            'format' => 'webp',
            'smartcrop' => '400x400',
            'destination' => ['disk' => 'public', 'path' => 'thumbnails/products/'],
            'quality' => 90,
            'smart_crop_enabled' => true,
            'silent_mode' => false, // Strict mode for admin debugging
            'subdirectory_strategy' => 'hash_prefix',
            'variants' => [
                'thumb' => ['smartcrop' => '120x120', 'quality' => 75],
                'card' => ['smartcrop' => '250x250', 'quality' => 85],
                'detail' => ['smartcrop' => '600x600', 'quality' => 95],
                'zoom' => ['smartcrop' => '1200x1200', 'quality' => 95],
            ]
        ],

        'avatars' => [
            'format' => 'webp',
            'smartcrop' => '100x100',
            'destination' => ['disk' => 'public', 'path' => 'thumbnails/avatars/'],
            'quality' => 80,
            'smart_crop_enabled' => true,
            'silent_mode' => true,  // Always safe for user content
            'subdirectory_strategy' => 'filename_prefix', // Organized by user initials
            'variants' => [
                'mini' => ['smartcrop' => '32x32', 'quality' => 70],
                'small' => ['smartcrop' => '64x64', 'quality' => 75],
                'medium' => ['smartcrop' => '128x128', 'quality' => 80],
                'large' => ['smartcrop' => '256x256', 'quality' => 85],
            ]
        ],

        'blog' => [
            'format' => 'webp',
            'smartcrop' => '800x450',
            'destination' => ['disk' => 'public', 'path' => 'thumbnails/blog/'],
            'quality' => 85,
            'smart_crop_enabled' => true,
            'silent_mode' => true,
            'subdirectory_strategy' => 'date_based', // Organized by publication date
            'variants' => [
                'card' => ['smartcrop' => '350x200', 'quality' => 80],
                'hero' => ['smartcrop' => '1200x675', 'quality' => 90],
                'social' => ['smartcrop' => '1200x630', 'quality' => 85], // Social media sharing
            ]
        ],

        'slider' => [
            'format' => 'webp',
            'smartcrop' => '1920x800',
            'destination' => ['disk' => 'public', 'path' => 'thumbnails/slider/'],
            'quality' => 90,
            'smart_crop_enabled' => true,
            'silent_mode' => true,  // Critical: sliders must never break
            'subdirectory_strategy' => 'date_based',
            'variants' => [
                'mobile' => ['smartcrop' => '768x400', 'quality' => 80],
                'tablet' => ['smartcrop' => '1024x540', 'quality' => 85],
                'desktop' => ['smartcrop' => '1920x800', 'quality' => 90],
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Optimization Profiles
    |--------------------------------------------------------------------------
    | Pre-defined optimization profiles for different content types
    */
    'optimization_profiles' => [
        'high_volume' => [
            'subdirectory_strategy' => 'hash_prefix',
            'webp_lossless' => false,
            'png_compression' => 9,
            'quality' => 75,
            'silent_mode' => true,
        ],
        'high_quality' => [
            'subdirectory_strategy' => 'date_based',
            'webp_lossless' => true,
            'png_compression' => 3,
            'quality' => 95,
            'silent_mode' => false,
        ],
        'fast_generation' => [
            'subdirectory_strategy' => 'none',
            'smart_crop_enabled' => false,
            'quality' => 70,
            'silent_mode' => true,
        ],
        'social_media' => [
            'subdirectory_strategy' => 'hash_prefix',
            'quality' => 85,
            'silent_mode' => true,
            'enable_progressive_jpeg' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN & Caching
    |--------------------------------------------------------------------------
    */
    'cdn_enabled' => false,                 // Enable CDN for thumbnail delivery
    'cdn_base_url' => null,                 // CDN base URL (e.g., https://cdn.example.com)
    'cache_headers' => [
        'Cache-Control' => 'public, max-age=31536000', // 1 year
        'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Processing
    |--------------------------------------------------------------------------
    */
    'batch_size' => 50,                     // Maximum thumbnails to process in batch
    'batch_timeout' => 300,                 // Batch processing timeout (5 minutes)
    'queue_enabled' => false,               // Use queue for async generation
    'queue_connection' => 'default',        // Queue connection to use

    /*
    |--------------------------------------------------------------------------
    | Maintenance & Cleanup
    |--------------------------------------------------------------------------
    */
    'auto_purge_old_thumbnails' => false,   // Auto-purge old unused thumbnails
    'purge_after_days' => 365,              // Days after which to purge unused thumbnails
    'maintenance_schedule' => 'weekly',     // Maintenance frequency: daily, weekly, monthly
];
