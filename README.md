# Laravel Smart Thumbnails

<p align="center">
  <img src="https://github.com/user-attachments/assets/3c71d5d7-19ca-4703-9612-d3eeaed23183" alt="Laravel Smart Thumbnails Demo" width="600"/>
</p>

**The ultimate thumbnail generation package for Laravel.** Featuring intelligent smart-cropping, multi-disk support, automatic subdirectory organization, and bulletproof error handling that ensures your application's UI never breaks.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Total Downloads](https://img.shields.io/packagist/dt/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Tests](https://img.shields.io/github/actions/workflow/status/askancy/laravel-smart-thumbnails/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/askancy/laravel-smart-thumbnails/actions)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](https://opensource.org/licenses/MIT)

---

## ✨ Features

- 🧠 **Intelligent Smart Crop Algorithm** - Energy-based detection ensures the most important parts of your images are never cropped out.
- 🛡️ **Bulletproof Error Handling** - Fails gracefully with automatic fallbacks (original image, placeholders, or generated SVGs) to guarantee your layout remains intact.
- 🖼️ **Modern Format Support** - Seamlessly generates modern image formats, including WebP and AVIF, for maximum compression and performance.
- 📁 **Massive Scale Subdirectories** - Handles millions of thumbnails efficiently via customizable subdirectory distribution strategies (e.g., hash prefixes, dates).
- 💾 **Multi-Disk Architecture** - Fully supports local, S3, scoped disks, and any custom Laravel filesystem.
- 🎨 **Responsive Variants** - Easily define multiple responsive presets (e.g., thumb, card, hero, zoom) within your configuration.
- ⚡ **High Performance & Cache** - Utilizes Smart Cache Tagging for targeted invalidation without flushing your entire application cache.
- 🎯 **Intervention Image 3.x Ready** - Full compatibility with the latest image manipulation APIs.

---

## 📋 Requirements

- **PHP** 8.1 or higher
- **Laravel** 10.0, 11.0, or 12.0
- **Intervention Image** 3.9+
- **GD** or **ImageMagick** PHP Extension (ImageMagick is required for AVIF support)
- *Optional:* Redis or Memcached (for cache tagging capabilities)

---

## 📦 Installation

Install the package via Composer:

```bash
composer require askancy/laravel-smart-thumbnails
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=laravel-smart-thumbnails-config
```

---

## 🚀 Quick Start

Laravel Smart Thumbnails is designed to be effortless. Use the facade directly in your Blade views:

### The Bulletproof "Silent" Mode (Recommended)
This mode **never** throws exceptions. If an image is missing or the disk fails, it silently falls back to a placeholder.

```blade
{{-- Implicit silent mode using urlSafe() --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3')->urlSafe() }}" alt="Gallery Image">

{{-- Explicit silent mode chaining --}}
<img src="{{ Thumbnail::silent()->set('products')->src($image, 'public')->url('thumb') }}" alt="Product Image">
```

### The "Strict" Mode (For Debugging / Admin Panels)
If you want exceptions to be thrown when something goes wrong (e.g., missing original file), use strict mode:

```blade
{{-- May throw an exception if the image is missing --}}
<img src="{{ Thumbnail::strict()->set('gallery')->src($photo->path, 's3')->url() }}" alt="Gallery">
```

### Advanced Responsive Pictures
Leverage configured variants to easily render `<picture>` tags for responsive designs:

```blade
<picture>
    <source media="(max-width: 640px)" srcset="{{ Thumbnail::set('blog')->src($post->image)->urlSafe('card') }}">
    <source media="(min-width: 641px)" srcset="{{ Thumbnail::set('blog')->src($post->image)->urlSafe('hero') }}">
    <img src="{{ Thumbnail::set('blog')->src($post->image)->urlSafe('hero') }}" alt="{{ $post->title }}" loading="lazy">
</picture>
```

---

## ⚙️ Configuration

Your `config/thumbnails.php` file is where the magic happens. You can define multiple "presets", each representing a distinct context (like `products`, `avatars`, `gallery`).

```php
'presets' => [
    'products' => [
        'format' => 'webp',                    // Target format (webp, avif, jpg, png)
        'smartcrop' => '400x400',              // Dimensions for the main thumbnail
        'destination' => [
            'disk' => 'public',                // Target storage disk
            'path' => 'thumbnails/products/'   // Target path
        ],
        'quality' => 85,
        'smart_crop_enabled' => true,          // Use the intelligent cropping algorithm
        'subdirectory_strategy' => 'hash_prefix', // Distribute files to prevent folder limits
        
        // Define responsive variants sharing the same preset logic
        'variants' => [
            'thumb'  => ['smartcrop' => '120x120',  'quality' => 75],
            'card'   => ['smartcrop' => '250x250',  'quality' => 85],
            'detail' => ['smartcrop' => '600x600',  'quality' => 95],
            'zoom'   => ['smartcrop' => '1200x1200', 'quality' => 95],
        ]
    ],
],
```

### AVIF Format Support
For ultra-modern web performance, AVIF provides ~50% smaller file sizes than JPEG and ~30% smaller than WebP.
To use AVIF, ensure you have the **ImageMagick** extension installed and update your driver in `config/thumbnails.php`:

```php
'intervention_driver' => 'imagick', // Must be 'imagick', GD does not support AVIF yet
```
Then, simply set `'format' => 'avif'` in your presets!

---

## 📁 Subdirectory Optimization

When you have millions of images, storing them all in a single folder slows down the filesystem drastically. Smart Thumbnails solves this automatically using customizable strategies:

- `'hash_prefix'` *(Recommended)*: Uses the first two characters of the MD5 hash (e.g. `a/b/image.jpg`). Perfectly distributes files.
- `'date_based'`: Organizes by generation date (e.g. `2025/12/31/image.jpg`).
- `'filename_prefix'`: Groups by the starting letters of the filename.
- `'hash_levels'`: Deeper, multi-level nesting for massive scale (e.g. `a/b/c/image.jpg`).

---

## 🛠️ Maintenance & CLI Commands

Keep your application fast and clean using the built-in Artisan commands:

```bash
# Purge all generated thumbnails across all disks
php artisan thumbnail:purge

# Purge thumbnails only for a specific preset
php artisan thumbnail:purge products --confirm
```

You can also analyze your usage via Tinker:
```php
php artisan tinker
>>> Thumbnail::getSystemStats();
>>> Thumbnail::analyzeDistribution('products');
```

---

## 🔄 Upgrade Guide (v2.0)

If you are upgrading from an older version, the transition is **100% backwards compatible**. No code changes are required in your views or controllers.

**Enhancements included automatically:**
- Upgrade to Intervention Image 3.x.
- Better memory management and Cache Tagging (Memcached/Redis recommended).
- Advanced Smart Cropping algorithm optimizations.

Make sure to clear your caches after upgrading:
```bash
php artisan cache:clear
php artisan config:clear
```

---

## 🤝 Contributing

We welcome pull requests and issues! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details on our code of conduct and development process.

Looking to help with our AI/Smart Crop Roadmap? Check out [SMART_CROP_ROADMAP.md](SMART_CROP_ROADMAP.md).

---

## 📄 License

The MIT License (MIT). Please see [LICENSE.md](LICENSE) for more information.

## 🙏 Credits

- Created and maintained by [Askancy](https://github.com/askancy)
- Image manipulation powered by [Intervention Image](https://github.com/Intervention/image)
- Smart crop algorithm inspired by [dont-crop](https://github.com/jwagner/dont-crop/)

---

> 💡 **Pro Tip**: Always use `->urlSafe()` when rendering images in public user-facing views to guarantee your frontend never breaks due to a missing file!
