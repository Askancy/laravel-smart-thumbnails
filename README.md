# Laravel Smart Thumbnails

Version **5.0.0**. See the [upgrade guide](#upgrade-guide) before upgrading from 4.x or earlier.

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

- 🧠 **Content-Aware Smart Crop** - Scores edges and color contrast to choose a crop, with center positioning when no content stands out.
- 🌈 **Dont-Crop Mode** - Preserves the complete image with padding using a fitted gradient, a dominant color, or a chosen background.
- 🛡️ **Bulletproof Error Handling** - Fails gracefully with automatic fallbacks (original image, placeholders, or generated SVGs) to guarantee your layout remains intact.
- 🖼️ **Modern Format Support** - Seamlessly generates modern image formats, including WebP and AVIF, for maximum compression and performance.
- 📁 **Massive Scale Subdirectories** - Handles millions of thumbnails efficiently via customizable subdirectory distribution strategies (e.g., hash prefixes, dates).
- 💾 **Multi-Disk Architecture** - Fully supports local, S3, scoped disks, and any custom Laravel filesystem.
- 🎨 **Responsive Variants** - Easily define multiple responsive presets (e.g., thumb, card, hero, zoom) within your configuration.
- ⚡ **High Performance & Cache** - Utilizes Smart Cache Tagging for targeted invalidation without flushing your entire application cache.
- 🎯 **Intervention Image 3.x Ready** - Full compatibility with the latest image manipulation APIs.

---

## 📋 Requirements

- **PHP** 8.2 or higher (8.x)
- **Laravel** 11.x or 12.x
- **Intervention Image** 3.9 or higher (3.x)
- **GD** PHP extension (required by Composer); **Imagick** is an optional processing driver
- **AVIF:** the selected driver must have AVIF codec support enabled
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

### Responsive Blade Component

Use the registered `<x-smart-thumbnail>` component to generate an `<img>` with `srcset`, `sizes`,
and the actual output dimensions:

```blade
<x-smart-thumbnail
    :src="$product->image"
    preset="products"
    disk="public"
    :variants="['thumb', 'card', 'detail']"
    sizes="(max-width: 640px) 100vw, 400px"
    :alt="$product->name"
    class="product-photo"
/>
```

By default, the main preset supplies `src`. Set `variant="detail_hero"` to use a configured variant as
the main image instead. That variant also supplies the actual intrinsic dimensions, the reference
aspect ratio for responsive candidates, and the fallback request.

```blade
<x-smart-thumbnail
    :src="$article->image"
    preset="news"
    variant="detail_hero"
    :variants="['detail_hero_sm']"
    sizes="(min-width: 1024px) 900px, 100vw"
    :alt="$article->title"
/>
```

Omit `variants` or pass `:variants="null"` to discover all configured variants. Pass `:variants="[]"`
to generate only the selected main image, without requesting alternate variants. A main-only `srcset`
may still contain its single actual-width descriptor. Explicitly empty lists no longer trigger automatic
discovery. If the selected main variant also appears in the candidates list, it is not requested twice.
Candidates are
sorted by actual width, duplicate widths are removed, and variants with a different aspect ratio are
excluded from `srcset`. Use `<picture>` for art direction with different ratios. The component uses
`loading="lazy"` and `decoding="async"` by default; normal HTML attributes can override these defaults.
If the main image cannot be generated, the component renders the configured fallback without invented
width descriptors. Unavailable variants do not prevent the main image from rendering.

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

### Smart Crop, Dont-Crop, and Center Crop

Choose `crop_mode` in a preset or variant:

| Mode | Behavior |
| --- | --- |
| `smart` | Fills the requested size by selecting a crop using image edges and color contrast. |
| `dont_crop` | Fits the whole source inside the requested size, centered, with padding. |
| `center` | Fills the requested size with a center crop. |

```php
'products' => [
    'format' => 'avif', // Also works with webp, jpg, and png
    'smartcrop' => '400x400', // Output dimensions for all three modes
    'destination' => ['disk' => 'public', 'path' => 'thumbnails/products/'],
    'crop_mode' => 'smart',
    'variants' => [
        'full' => [
            'crop_mode' => 'dont_crop',
            'dont_crop_background' => 'gradient',
        ],
    ],
],
```

```php
Thumbnail::set('products')->src('photos/example.avif', 'public')->urlSafe();
Thumbnail::set('products')->src('photos/example.avif', 'public')->urlSafe('full');
```

`dont_crop_background` accepts `gradient` (default), `dominant`, or an Intervention Image color
such as `#ffffff` or `transparent`. Use PNG, WebP, or AVIF output for transparency.
The gradient is fitted to sampled source colors along the padding direction. The foreground keeps its
aspect ratio and, by default, may be enlarged to fit the output dimensions.

The global `crop_mode` defaults to `null` for compatibility: when no explicit mode is selected,
`smart_crop_enabled` in the preset, or the global `enable_smart_crop`, selects smart or center cropping.
An explicit `crop_mode` overrides those flags. Variants inherit preset options and can override the mode
and background. Global `crop_mode` and `dont_crop_background` supply defaults.

Smart analysis uses a sample of at most 96 × 96 pixels, for both synchronous generation and queue jobs,
including large images. It is a heuristic based on visual detail, without face or object recognition;
it cannot guarantee that every subject remains inside a crop. Choose `dont_crop` when retaining every
part of the source matters. This package provides independent PHP implementations inspired by the ideas
behind [smartcrop.js](https://github.com/jwagner/smartcrop.js) and
[dont-crop](https://github.com/jwagner/dont-crop), not ports of their complete algorithms.

You can also extract gradient colors and a palette directly:

```php
use Askancy\LaravelSmartThumbnails\Services\DontCropService;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;

$image = ThumbnailGenerator::createImageManager()->read($imageBytes);
$service = app(DontCropService::class);
$gradient = $service->fitGradient($image); // start, end (hex colors), direction
$palette = $service->getPalette($image, 5); // Up to 5 hex colors, most common first
```

Gradient fitting uses opacity-weighted linear regression in RGB. Palette extraction uses a weighted
RGB histogram; these are simplified alternatives to dont-crop's Lab-based gradient and palette processing.
Fully transparent sources use white as the automatic background fallback.

### Avoid Enlarging Small Images

Set `allow_upscale` globally, in a preset, or in a variant (default: `true` for compatibility):

```php
'allow_upscale' => false,
```

With `smart` or `center`, output dimensions shrink proportionally when the source cannot fill the requested
crop at its native resolution. For example, a 40 × 20 source requested as 100 × 100 produces 20 × 20 pixels.
With `dont_crop`, the canvas retains the requested dimensions and the foreground is never enlarged;
the remaining area is filled by the selected background. Variants can set `allow_upscale => true`
independently. The Blade component uses the resulting dimensions, rather than the requested dimensions.

### Source Identity, Freshness, and Validation

On remote disks (including scoped S3/Spaces disks), thumbnail paths are derived from the source disk,
full path, storage configuration, rendering settings and variant. **No source content hash or cache
entry is needed to find an existing thumbnail.** `url()` and `urlSafe()` check the derivative and return
its URL without reading the original. `path()` computes the path without source I/O, and `exists()`
checks only the derivative. This remains true after cache eviction, a complete Laravel cache flush,
and days of inactivity. There is no periodic source revalidation.

```php
'source_identity' => 'auto', // Remote: deterministic path; local: content hash (legacy behavior)
// 'path': deterministic paths on all disks
// 'content': hash original bytes on each URL/path/existence lookup, including remote disks
```

This option may also be overridden per preset or variant. The fluent `strict()` method controls
exception handling, not source identity. Different source paths/disks, scoped storage configurations,
render settings and variants have distinct identities. In path mode, `date_based` subdirectories use
stable hash prefixes instead of today's date, avoiding a new original download each day.

If a derivative is missing, the source is read and validated inside the generation lock, then the
complete thumbnail is published. Queue workers follow the same existence-first policy. Byte limits,
pixel limits, extension/MIME checks and supported-format checks still apply during generation.
Failures are not cached as successes. Disabling `cache_thumbnails` explicitly forces generation and
therefore reads the original; disabling URL or metadata caching alone does not.

**Source replacements:** changing the original at the same path does not automatically update existing
remote thumbnails, even after clearing Laravel's cache. After replacing or deleting the original, call:

```php
Thumbnail::src($path, 's3')->invalidateSource();
```

This deletes its managed derivatives across presets/variants, using the private ownership manifests
and the same source/derivative locks as generation. Unrelated sources and untracked files are preserved.
The next request generates from the current original. Keep the private manifests available to all web
and queue instances; invalidation scans these records, so it is a write-time operation, not a page-render
operation. `clearCache()` only clears application cache entries and does not invalidate image files.
`regenerate($variant)` and `imageDataFresh($variant)` explicitly refresh one selected derivative.

The deterministic URL stays the same after regeneration. A browser/CDN may still hold its previous
contents: purge that URL from the CDN or, preferably, upload the replacement under a new versioned source
path. A `source_version` value in a preset/variant also selects a new output identity when changed.
Use `source_identity => 'content'` if automatic byte-based source versioning is required; this restores
original reads on every URL/path/existence lookup. Metadata caching still has its own TTL in that mode.

**Migration:** remote path identities use a new `v4-path` namespace. Existing content-hashed thumbnails
cannot be discovered from the source path alone; the first request generates the new deterministic
version. Subsequent requests reuse it even with an empty application cache. Old saved URLs/files are
not rewritten or deleted automatically. Use the existing obsolete-version cleanup when ready. Local
content identities remain compatible. Rebuild Laravel's config cache during deployment.

**Immediate mitigation on 4.3.0 before upgrading:** use `imageData()['url']` or the native Blade component
with `cache_image_data => true`, a positive `image_data_cache_ttl`, and a persistent shared cache. Warming
`cache_urls` alone does not stop original downloads in that version. Metadata can stay stale until its
TTL; call `imageDataFresh()` after replacements. This mitigation reduces reads but does not provide the
cache-independent behavior of the new path identity.

### Cached Image Metadata for Responsive Rendering

`imageData()` and the Blade component cache successful **URL + actual width/height** for 300 seconds
by default. Warm metadata hits do not read either image. On a metadata miss in path mode, the existing
derivative is measured without downloading the original. The dimensions reflect the actual generated
file, including when `allow_upscale` is disabled.

```php
'cache_image_data' => true,
'image_data_cache_ttl' => 300, // Non-negative seconds; 0 disables metadata caching
```

This cache is independent of `cache_urls`. Disabling metadata caching measures the derivative on every
call; it does not force original reads in path mode. Disabling `cache_thumbnails` bypasses metadata
caching and forces generation. Cache hits do not extend expiration. Cache identities include source,
variant, rendering/validation settings, identity mode, disk configuration and URLs. Failures and fallback
URLs are never stored as successful metadata.

A deleted derivative can remain invisible to a metadata hit until its TTL expires. Use
`imageDataFresh($variant)` for an immediate refresh: path mode regenerates the selected thumbnail,
while content mode checks the current original identity and reuses its derivative when available.
`invalidateSource()` removes all managed variants of a replaced source; `clearCache()` clears only
cached metadata/URLs. Purges, forced generation and completed queue generation invalidate the relevant
metadata namespaces. Use a shared cache and lock store across web/queue instances for coordinated
invalidation and generation.

`max_file_size` is enforced before reading the full source and again against the bytes read. It defaults
to 10 MiB; set it to `0` to disable the byte limit. With `validate_image_content => true`, the package checks
the extension allowlist, recognizes the image header, and verifies that MIME type matches the extension.
Disabling content validation does not disable the byte or pixel limits. These checks apply both to normal
requests and queued jobs. Invalid output formats, dimensions, quality, and PNG compression are rejected.

For legacy files whose names do not match their actual format (for example, PNG bytes stored as
`legacy.jpg`), explicitly enable `allow_mismatched_extensions` in the affected preset or variant:

```php
'news' => [
    // Other preset settings...
    'allow_mismatched_extensions' => true,
    'variants' => [
        'strict_preview' => [
            'width' => 500,
            'height' => 300,
            'allow_mismatched_extensions' => false,
        ],
    ],
],
```

The default is `false`. A variant inherits its preset's setting unless overridden; a preset inherits
the global `thumbnails.allow_mismatched_extensions` setting. In compatibility mode, `allowed_extensions`
applies to the format detected from the bytes, and content validation always runs, even if
`validate_image_content` is disabled. Byte and pixel limits still apply. PNG, WebP, AVIF, JPEG and GIF
are decoded by their content when supported by the configured image driver. Original files remain
untouched, and this input policy does not change thumbnail paths for the same source and render settings.

The policy applies to URLs, metadata, existence/path checks, regeneration, Blade rendering, queued jobs
and obsolete-version cleanup. Pregeneration scans all candidate filenames when a selected preset or
variant enables compatibility, then validates each candidate against that configuration. A rejected main
image does not prevent an explicitly compatible variant from being generated; rejected candidates still
count as command errors. Changing the policy selects a separate metadata cache entry. Remote source
replacements require invalidation by default; use `imageDataFresh()` for immediate verification and refresh.

`cache_urls`, `cache_thumbnails`, and the URL `cache_ttl` are honored. Purges invalidate the relevant
thumbnail URL namespace, including with file/database cache stores, without clearing unrelated application
data. `png_compression`, `webp_lossless`, and `enable_progressive_jpeg` can be overridden per preset or variant
and are applied by the encoder.

**Migration:** the new identity-based paths intentionally differ from legacy paths. New requests generate
the current versions automatically; stored old URLs are not rewritten. To remove old thumbnails, purge
the affected preset (including its configured variant destinations):

```bash
php artisan thumbnail:purge products --confirm
```

### AVIF Format Support
AVIF is supported both as a source image and as a thumbnail output format.
Both **GD** and **Imagick** can process AVIF when their installed libraries include AVIF support.
The source format is detected from the image contents. When you request a thumbnail with `url()` or
`urlSafe()`, the package reads the source and crops it to the preset's `smartcrop` dimensions.
The selected crop mode applies equally to AVIF and other source formats. Uploading a file alone does not
trigger thumbnail generation.

For example, with the default `gallery` preset, this reads an AVIF source, applies the configured crop,
and produces a WebP thumbnail:

```php
Thumbnail::set('gallery')->src('photos/example.avif', 'public')->urlSafe();
```

The output format comes from the preset's `format` setting, or `default_format` when omitted;
it is independent of the source format.
Set the output format in `config/thumbnails.php`:

```php
'presets' => [
    'gallery' => [
        'format' => 'avif',
        'smartcrop' => '300x200',
        'destination' => ['disk' => 'public', 'path' => 'thumbnails/gallery/'],
        'quality' => 85,
    ],
],
```
Check the installed driver with `ThumbnailGenerator::createImageManager()->driver()->supports('avif')`
(`ThumbnailGenerator` is in the `Askancy\LaravelSmartThumbnails\Support` namespace).
See [Intervention Image's supported formats](https://image.intervention.io/v3/getting-started/formats).
If you already published the configuration, add `'avif'` to `allowed_extensions` to accept AVIF sources.
An explicitly configured allowlist is always respected. If encoding is unavailable, strict mode reports an
exception and silent mode uses the configured fallback. No different format is written under an `.avif` extension.

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

# Pre-generate a preset from images/ on the public disk
php artisan thumbnails:pregenerate products --disk=public --path=images/

# Dispatch generation to the configured Laravel queue
php artisan thumbnails:pregenerate products --disk=public --path=images/ --async

# Regenerate even when a thumbnail already exists (also works with --async)
php artisan thumbnails:pregenerate products --disk=public --path=images/ --force

# Process the default thumbnail queue
php artisan queue:work --queue=thumbnails
```

For one source, use `Thumbnail::set('products')->src($path)->regenerate()` or
`->regenerate('thumb')`. Normal `url()` calls reuse the current file; regeneration overwrites it.

### Concurrent Generation and Publication

Web requests and queue jobs acquire the same atomic lock for each destination disk/path and recheck
whether the thumbnail exists after acquiring it. Normal simultaneous requests render once; explicit
forced regenerations run in sequence. Locks are released even when rendering fails.

Images are written to unique temporary files and published with a storage `move` only after the bytes
are complete. The writer verifies that it still owns the lock before publication. A failed temporary
write or move leaves an existing thumbnail intact. Local disks use completed-file rename semantics;
remote destinations rely on the adapter's move/copy publication semantics.

```php
'lock_store' => 'redis',          // A configured Laravel cache store
'generation_lock_seconds' => 300,
'generation_lock_wait' => 10,
```

The default `lock_store => null` follows the application's cache store. All web and queue instances
writing to the same destination must use the same lock store and cache prefix. Use file cache for a
single host or a shared atomic-lock store for multiple hosts. An in-memory `array` cache cannot coordinate
independent processes. Set the lock lifetime above the longest render/upload duration. On lock timeout,
strict mode throws, silent mode falls back, and queued jobs can retry using their normal retry policy.

### Prune Obsolete Versions

```bash
# Preview obsolete versions at least 30 days old
php artisan thumbnails:prune --dry-run

# Delete eligible obsolete versions for one preset
php artisan thumbnails:prune products --days=30

# Use a longer retention period
php artisan thumbnails:prune --days=90
```

The retention default is `obsolete_retention_days => 30`; `--days=0` allows immediate pruning of
obsolete versions. Age alone never makes a current version eligible. The command checks the source
content and current preset/variant settings under the same lock used by generation, then verifies
that the output still matches its recorded hash before deleting it. Old versions of removed sources,
presets, and variants are eligible after the retention period as well.

The report distinguishes current, recent, modified, missing, obsolete, deleted, busy, and error cases.
Busy outputs are skipped for a later run. Source read/validation errors preserve the affected output;
outputs replaced outside the package are also preserved. Dry runs delete neither images nor records.
Current source validation settings apply during cleanup, so keep source disks accessible.

Ownership records are stored privately, separately from the generated public images:

```php
'manifest_disk' => 'local',
'manifest_path' => 'smart-thumbnails/manifests',
'obsolete_retention_days' => 30,
```

Use a private persistent disk; multiple application instances should share this index for complete
cleanup coverage. Only successfully indexed managed outputs are considered. **Files generated before
ownership tracking was introduced, untracked files, and custom job destinations are preserved.** Use
`thumbnail:purge` when you intentionally want to remove legacy thumbnails too. Cleanup does not remove
source images or unrelated application cache data. Old URLs may stop working after their versions are
pruned, so choose a retention period appropriate for cached pages or saved links.

To run cleanup periodically, register `Schedule::command('thumbnails:prune')->weekly()` in your Laravel
application's scheduler. The command does not schedule itself.

You can also analyze your usage via Tinker:
```php
php artisan tinker
>>> Thumbnail::getSystemStats();
>>> Thumbnail::analyzeDistribution('products');
```

---

<a id="upgrade-guide"></a>

## 🔄 Upgrade Guide (5.0.0)

When upgrading from an older version, check the PHP, Laravel, and Intervention Image requirements above
and review your published configuration, including `allowed_extensions` for AVIF sources.

For applications upgrading from 4.x:

1. Install `askancy/laravel-smart-thumbnails:^5.0` with Composer.
2. Remote/scoped remote disks now use deterministic path identities by default. The first access
   generates a new derivative; subsequent lookups do not read originals, even after cache flushes.
   Keep old thumbnail files while existing links still use them.
3. After replacing an original at the same path, call `Thumbnail::src($path, $disk)->invalidateSource()`
   or explicitly regenerate the selected variant. Clearing application cache does not invalidate files.
   Prefer versioned source paths for replacements; otherwise purge the affected CDN URLs.
4. To retain the 4.x behavior and paths on remote disks, set `source_identity => 'content'` globally
   or per preset. This restores original downloads for URL/path/existence checks. Local disks retain
   content identities under the default `auto` setting.
5. Keep the private ownership manifests and lock store available to all web/queue instances.
   In path mode, `date_based` layouts now use stable hash prefixes to avoid daily regeneration.

For applications upgrading from 3.x, also apply these changes:

1. Update the Composer constraint to `^5.0` and install the release:
   ```bash
   composer require askancy/laravel-smart-thumbnails:^5.0
   ```
2. Merge the new settings into your published configuration, especially `allow_upscale`, `lock_store`,
   `generation_lock_seconds`, `generation_lock_wait`, `manifest_disk`, `manifest_path`, and
   `obsolete_retention_days`. Use a private writable manifest disk and an atomic lock store shared by
   all web and queue instances writing to the same destination. Avoid process-local array cache in production.
3. Review the enforced 10 MiB source byte limit and MIME/extension matching. AVIF sources require
   `avif` in the allowlist and a driver with the AVIF codec enabled.
4. New requests use identity-based thumbnail paths. Stored old URLs are not rewritten. Update stored URLs
   through the package API as needed, and retain old files until existing links no longer need them.
   `thumbnail:purge` can remove legacy thumbnails; `thumbnails:prune` only removes indexed obsolete versions.
5. If you extend `SmartCropService` or override `ThumbnailService::generateThumbnailSafe()`, review the
   changed internals and the generation lock argument. The fluent facade API remains available.

Clear compiled configuration and restart long-running queue workers after deployment:
```bash
php artisan config:clear
php artisan queue:restart
```

The version is supplied by the Git release tag; `composer.json` intentionally has no hardcoded `version` field.

---

## 🤝 Contributing

We welcome pull requests and issues!

Looking to help with our AI/Smart Crop Roadmap? Check out [SMART_CROP_ROADMAP.md](SMART_CROP_ROADMAP.md).

---

## 📄 License

The MIT License (MIT). Please see [LICENSE.md](LICENSE) for more information.

## 🙏 Credits

- Created and maintained by [Askancy](https://github.com/askancy)
- Image manipulation powered by [Intervention Image](https://github.com/Intervention/image)
- Content-aware cropping inspired by [smartcrop.js](https://github.com/jwagner/smartcrop.js)
- Gradient backgrounds and palette extraction inspired by [dont-crop](https://github.com/jwagner/dont-crop/)

---

> 💡 **Pro Tip**: Always use `->urlSafe()` when rendering images in public user-facing views to guarantee your frontend never breaks due to a missing file!
