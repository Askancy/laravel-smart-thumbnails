# Laravel Smart Thumbnails

<p align="center">
  <img src="https://github.com/user-attachments/assets/3c71d5d7-19ca-4703-9612-d3eeaed23183" alt="Laravel Smart Thumbnails Demo" width="600"/>
</p>

The most **advanced thumbnail generation package** for Laravel with intelligent cropping, multi-disk support, **subdirectory organization**, and **bulletproof error handling** that never breaks your application.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Total Downloads](https://img.shields.io/packagist/dt/askancy/laravel-smart-thumbnails.svg?style=flat-square)](https://packagist.org/packages/askancy/laravel-smart-thumbnails)
[![Tests](https://img.shields.io/github/actions/workflow/status/askancy/laravel-smart-thumbnails/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/askancy/laravel-smart-thumbnails/actions)

## ✨ What's New in Latest Version

### Major Improvements
- 🎯 **Intervention Image 3.x Support** - Full compatibility with the latest Intervention Image version
- 🏷️ **Smart Cache Tagging** - Targeted cache invalidation without affecting your entire application cache
- 🧩 **Refactored Architecture** - New `ThumbnailGenerator` utility class for better code reusability
- 🧠 **Enhanced Smart Crop** - Improved rule-of-thirds algorithm with better image analysis
- 📝 **Complete PHPDoc** - Full documentation for all public methods and classes

### Performance & Reliability
- ⚡ **Faster Cache Operations** - Consistent 1-hour TTL across all cache layers
- 🎨 **Better Memory Management** - Automatic cleanup with Intervention Image 3.x
- 🔧 **No Breaking Changes** - Fully backward compatible with existing implementations

> **📚 See the [Upgrade Guide](#-upgrade-guide) for migration instructions**
> **📋 See [CHANGELOG.md](CHANGELOG.md) for complete version history**

## 🚀 Features

- ✨ **Smart Crop Algorithm** - Based on [dont-crop](https://github.com/jwagner/dont-crop/) with energy detection
- 🛡️ **Bulletproof Error Handling** - Never breaks your application, always shows something
- 📁 **Subdirectory Organization** - Handles millions of thumbnails with optimal filesystem performance
- 💾 **Multi-Disk Support** - S3, local, scoped disks, and custom storage solutions
- 🎨 **Multiple Variants** - Responsive design with preset variants
- 🚀 **Lazy Generation** - Thumbnails created only when needed
- 🔄 **Intelligent Fallbacks** - Original image → Custom placeholder → Generated placeholder
- ⚡ **High Performance** - Optimized for large-scale applications
- 🗑️ **Maintenance Commands** - Purge, optimize, and analyze thumbnails
- 🧪 **Fully Tested** - Comprehensive PHPUnit test suite

## 📋 Requirements

- PHP 8.1+
- Laravel 10.0, 11.0, or 12.0
- Intervention Image 3.9+
- GD extension (required) or ImageMagick extension (recommended)
- Redis or Memcached extension (optional, for cache tagging support)

## 📦 Installation

Install via Composer:

```bash
composer require askancy/laravel-smart-thumbnails
```

Publish configuration:

```bash
php artisan vendor:publish --tag=laravel-smart-thumbnails-config
```

## 🛡️ Error-Safe Usage (Recommended)

The package offers **bulletproof error handling** that ensures your application never breaks due to missing images or storage issues.

### **Silent Mode (Never Fails)**

```blade
{{-- ✅ NEVER throws exceptions, always shows something --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3')->urlSafe() }}" alt="Gallery">

{{-- ✅ Explicit silent mode --}}
<img src="{{ Thumbnail::silent()->set('products')->src($image, 's3')->url('thumb') }}" alt="Product">
```

### **Strict Mode (For Development/Admin)**

```blade
{{-- ⚠️ May throw exceptions for debugging --}}
<img src="{{ Thumbnail::strict()->set('gallery')->src($photo->path, 's3')->url() }}" alt="Gallery">

{{-- ⚠️ Standard mode (configurable default) --}}
<img src="{{ Thumbnail::set('gallery')->src($photo->path, 's3')->url('large') }}" alt="Gallery">
```

## 🎯 Quick Examples

### **Responsive Blog Headers**

```blade
<picture>
    <source media="(max-width: 640px)" 
            srcset="{{ Thumbnail::set('blog')->src($post->image, 's3')->urlSafe('card') }}">
    <source media="(min-width: 641px)" 
            srcset="{{ Thumbnail::set('blog')->src($post->image, 's3')->urlSafe('hero') }}">
    <img src="{{ Thumbnail::set('blog')->src($post->image, 's3')->urlSafe('hero') }}" 
         alt="{{ $post->title }}"
         loading="lazy">
</picture>
```

### **Homepage Slider (Never Breaks)**

```blade
<div class="hero-slider">
    @foreach($slides as $slide)
        <div class="slide">
            {{-- This slider will NEVER break, even with missing images --}}
            <img src="{{ Thumbnail::set('slider')->src($slide->image, 's3')->urlSafe('desktop') }}"
                 alt="Hero Slide"
                 loading="lazy">
        </div>
    @endforeach
</div>
```

## ⚙️ Advanced Configuration

### **Multi-Disk Setup**

```php
// config/filesystems.php
'disks' => [
    's3_products' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'products',
    ],
    's3_gallery' => [
        'driver' => 'scoped',
        'disk' => 's3',
        'prefix' => 'gallery',
    ],
],
```

### **Preset Configuration**

```php
// config/thumbnails.php
'presets' => [
    'products' => [
        'format' => 'webp',
        'smartcrop' => '400x400',
        'destination' => ['disk' => 's3_products', 'path' => 'thumbnails/'],
        'quality' => 90,
        'smart_crop_enabled' => true,
        'silent_mode' => false, // Strict for admin
        'subdirectory_strategy' => 'hash_prefix', // Optimal for high volume
        'variants' => [
            'thumb' => ['smartcrop' => '120x120', 'quality' => 75],
            'card' => ['smartcrop' => '250x250', 'quality' => 85],
            'detail' => ['smartcrop' => '600x600', 'quality' => 95],
            'zoom' => ['smartcrop' => '1200x1200', 'quality' => 95],
        ]
    ],
],
```

## 📁 Subdirectory Organization

Handle millions of thumbnails efficiently with automatic subdirectory organization:

### **Hash Prefix Strategy (Recommended)**
```
thumbnails/products/
├── a/b/ (47 files)
├── c/d/ (52 files)
├── e/f/ (48 files)
└── ... (256 total directories)
```

### **Date-Based Strategy**
```
thumbnails/blog/
├── 2025/01/28/ (today's posts)
├── 2025/01/27/ (yesterday's posts)
└── 2025/01/26/ (older posts)
```

### **Configuration**

```php
'subdirectory_strategy' => 'hash_prefix',    // Uniform distribution (recommended)
'subdirectory_strategy' => 'date_based',     // Organized by date
'subdirectory_strategy' => 'filename_prefix', // By filename initials
'subdirectory_strategy' => 'hash_levels',    // Multi-level (a/b/c/)
'subdirectory_strategy' => 'none',           // No subdirectories
```

## 🧠 Smart Crop Algorithm

Advanced intelligent cropping based on image energy analysis:

```php
// Enable smart crop for better results
'smart_crop_enabled' => true,  // Uses energy detection algorithm
'smart_crop_enabled' => false, // Uses simple center crop
```

**How it works:**
- Analyzes image energy using gradient magnitude
- Finds areas of interest based on contrast and details
- Avoids aggressive cropping that removes important subjects
- Uses rule of thirds for optimal positioning

## 🛠️ Artisan Commands

### **Purge Thumbnails**

```bash
# Purge all thumbnails
php artisan thumbnail:purge

# Purge specific preset
php artisan thumbnail:purge products

# Silent purge (no confirmation)
php artisan thumbnail:purge products --confirm
```

### **Statistics & Analysis**

```bash
php artisan tinker
>>> Thumbnail::analyzeDistribution('products')
>>> Thumbnail::getSystemStats()
>>> Thumbnail::optimize() // Remove duplicates and empty directories
```

## 🎛️ Advanced Features

### **Conditional Error Handling**

```blade
{{-- Admin sees errors, users get safe fallbacks --}}
@admin
    <img src="{{ Thumbnail::strict()->set('gallery')->src($image, 's3')->url() }}" alt="Gallery">
@else
    <img src="{{ Thumbnail::silent()->set('gallery')->src($image, 's3')->url() }}" alt="Gallery">
@endadmin
```

### **Performance Optimization**

```php
// config/thumbnails.php
'optimization_profiles' => [
    'high_volume' => [
        'subdirectory_strategy' => 'hash_prefix',
        'quality' => 75,
        'silent_mode' => true,
    ],
    'high_quality' => [
        'quality' => 95,
        'webp_lossless' => true,
        'silent_mode' => false,
    ],
],
```

### **Batch Processing**

```php
// Process multiple thumbnails efficiently
'batch_size' => 50,
'batch_timeout' => 300,
'queue_enabled' => true, // Use Laravel queues
```

### **Security Features**

```php
'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
'max_file_size' => 10 * 1024 * 1024, // 10MB
'validate_image_content' => true,
'sanitize_filenames' => true,
```

## 📊 Monitoring & Statistics

### **System Analysis**

```php
// Get complete system statistics
$stats = Thumbnail::getSystemStats();
// Returns: total files, size, distribution by disk, preset analysis

// Analyze specific preset
$analysis = Thumbnail::analyzeDistribution('products');
// Returns: file count, size, directory distribution, format breakdown
```

### **Performance Monitoring**

```php
'enable_stats' => true,
'log_generation_time' => true,
'monitor_disk_usage' => true,
```

## 🔧 Troubleshooting

### **Common Issues**

```bash
# Test disk connectivity
php artisan tinker
>>> Thumbnail::testDisk('s3_products')

# Validate configuration
>>> Thumbnail::validateConfiguration()

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear
```

### **Debug Mode**

```blade
{{-- Debug information in development --}}
@if(app()->environment('local'))
    @foreach(Thumbnail::getAvailableDisks() as $disk)
        @php $status = Thumbnail::testDisk($disk) @endphp
        <p>{{ $disk }}: {{ $status['accessible'] ? '✅' : '❌' }}</p>
    @endforeach
@endif
```

## 📈 Performance Benefits

| Files | Without Subdirectories | With Hash Prefix |
|-------|----------------------|------------------|
| 1,000 | ⚠️ Slow | ✅ Fast |
| 10,000 | ❌ Very Slow | ✅ Fast |
| 100,000 | ❌ Unusable | ✅ Fast |
| 1,000,000 | ❌ Impossible | ✅ Fast |

**With subdirectories:**
- 📈 **200x faster** filesystem operations
- 🚀 **Instant** directory listings
- ⚡ **Efficient** backup and sync
- 🎯 **Optimal** for CDN delivery

## 🔄 Upgrade Guide

### Upgrading to Latest Version (Intervention Image 3.x)

The latest version includes major improvements while maintaining **100% backward compatibility**. No code changes are required!

#### What Changed

**✅ Automatic Improvements** (No action required)
- Intervention Image upgraded from 2.x to 3.x
- Cache system now uses tags for targeted invalidation
- Memory management improved
- Smart crop algorithm enhanced

#### Recommended Actions

**1. Update Dependencies**

```bash
composer update askancy/laravel-smart-thumbnails
```

**2. Configure Cache Driver** (For optimal cache tagging)

If using `file` or `database` cache drivers, consider upgrading to Redis or Memcached for cache tagging support:

```php
// config/cache.php
'default' => env('CACHE_DRIVER', 'redis'), // or 'memcached'
```

**Cache drivers that support tagging:**
- ✅ Redis (recommended)
- ✅ Memcached
- ✅ Array (testing only)
- ❌ File (falls back to non-tagged cache)
- ❌ Database (falls back to non-tagged cache)

**3. Optional: Enable ImageMagick** (Better quality)

```bash
# Install ImageMagick extension
pecl install imagick

# Then update config
// config/thumbnails.php
'intervention_driver' => 'imagick', // Changed from 'gd'
```

**4. Verify Configuration**

```bash
php artisan tinker
>>> Thumbnail::validateConfiguration()
```

#### New Configuration Options

Add these to your `config/thumbnails.php` (optional, defaults shown):

```php
/*
|--------------------------------------------------------------------------
| Cache Settings
|--------------------------------------------------------------------------
*/
'cache_urls' => true,        // Enable URL caching
'cache_ttl' => 3600,         // Cache TTL in seconds (1 hour)
```

#### Breaking Changes

**None!** This is a fully backward-compatible update.

#### Troubleshooting

**Issue: Cache not working after upgrade**
```bash
# Clear Laravel cache
php artisan cache:clear
php artisan config:clear
```

**Issue: "Driver not found" error**
```bash
# Install Intervention Image GD driver
composer require intervention/image-gd

# Or for ImageMagick
composer require intervention/image-imagick
```

**Issue: Thumbnails not generating**
```bash
# Test your configuration
php artisan tinker
>>> Thumbnail::set('gallery')->debugInfo()
```

### Performance Optimization Tips

**1. Warm up cache for existing thumbnails:**
```php
// In a command or controller
$images = ['image1.jpg', 'image2.jpg', ...];
$results = Thumbnail::warmUpCache($images, 'gallery', ['thumb', 'medium']);
// Returns: ['warmed' => 50, 'already_cached' => 25, 'errors' => 0]
```

**2. Use preset-specific cache clearing:**
```php
// Clear only thumbnails cache (not entire app cache)
Cache::tags(['thumbnails'])->flush();

// Clear specific preset cache
Cache::tags(['thumbnails', 'gallery'])->flush();
```

**3. Monitor cache performance:**
```php
$metrics = Thumbnail::set('gallery')->src('photo.jpg')->getPerformanceMetrics();
// Returns execution time, memory usage, cache hit/miss info
```

## 🚀 Roadmap

We have exciting enhancements planned for the smart crop algorithm! See [SMART_CROP_ROADMAP.md](SMART_CROP_ROADMAP.md) for:

- 🎯 **Edge Detection** - Sobel filter for identifying image edges
- 🧠 **Entropy Analysis** - Texture and detail detection
- 👤 **Face Detection** - Automatic face preservation
- 🗺️ **Saliency Maps** - Visual attention modeling
- 🤖 **Machine Learning** - AI-powered object detection

Contributions welcome! Check the roadmap for implementation details and priorities.

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

For implementing smart crop enhancements, refer to [SMART_CROP_ROADMAP.md](SMART_CROP_ROADMAP.md).

## 📄 License

MIT License. See [LICENSE.md](!LICENSE.md) for details.

## 🙏 Credits

- [Askancy](https://github.com/askancy)
- [Intervention Image](https://github.com/Intervention/image)
- [dont-crop algorithm](https://github.com/jwagner/dont-crop/)
- All [contributors](https://github.com/askancy/laravel-smart-thumbnails/contributors)

---

> 💡 **Pro Tip**: Always use `urlSafe()` or `silent()` for public-facing content and reserve `strict()` mode for admin interfaces and development!
