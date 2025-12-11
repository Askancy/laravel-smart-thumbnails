# Changelog

All notable changes to `laravel-smart-thumbnails` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2025-12-11

### Added
- **AVIF Format Support**: Next-generation image format with superior compression
  - Added AVIF to supported output formats in `ThumbnailGenerator::convertFormat()`
  - Added `'avif'` to allowed file extensions in configuration
  - 50% smaller file sizes compared to JPEG at same quality
  - 30% smaller than WebP in most cases
  - Requires ImageMagick driver (not available with GD)
  - Comprehensive documentation in README with usage examples
  - Format comparison table and browser support information

- **Intervention Image 3.x Support**: Full compatibility with Intervention Image 3.9+
  - New `ThumbnailGenerator` utility class with static helpers
  - Updated API usage from `ImageManagerStatic` to `ImageManager`
  - Support for both GD and ImageMagick drivers

- **Smart Cache Tagging**: Targeted cache invalidation without affecting application cache
  - Cache tags for thumbnails, config, paths, and exists checks
  - Consistent cache TTL (1 hour) across all operations
  - `purgeAll()` now uses cache tags instead of `Cache::flush()`
  - Preset-specific cache invalidation support

- **Enhanced Smart Crop Algorithm**: Improved rule-of-thirds implementation
  - Multiple weighted interest points based on composition rules
  - Better horizontal and vertical crop positioning
  - Documented roadmap for future AI/ML enhancements
  - Comprehensive PHPDoc for all methods

- **ThumbnailGenerator Utility Class**: Centralized helper methods
  - `createImageManager()` - Factory for Intervention Image initialization
  - `applyBasicCrop()` - Center-aligned crop fallback
  - `convertFormat()` - Format conversion with optimization
  - `ensureDestinationDirectory()` - Safe directory creation
  - `diskSupportsVisibility()` - Visibility support detection
  - `setPublicVisibility()` - Automatic public visibility
  - `formatBytes()` - Human-readable byte formatting
  - `sanitizeFilename()` - Secure filename sanitization
  - `validateImageContent()` - Image validation
  - `generateSubdirectory()` - All subdirectory strategies

- **New Configuration Options**:
  - `cache_urls` - Enable/disable URL caching
  - `cache_ttl` - Configurable cache TTL in seconds

- **Composer Enhancements**:
  - Added `intervention/image-gd` as required dependency
  - Suggested packages for ImageMagick, Redis, and Memcached
  - Additional composer scripts for testing and analysis
  - Added `avif` and `webp` keywords
  - Updated suggestions to mention AVIF format support

### Changed
- **Breaking**: None! All changes are backward compatible

- **Refactored**: `ThumbnailService` to use new `ThumbnailGenerator` helpers
  - Removed ~250 lines of duplicate code
  - Improved code maintainability and testability
  - Better separation of concerns

- **Refactored**: `GenerateThumbnailJob` to use new utilities
  - Simplified image processing logic
  - Better error handling with fallbacks
  - Improved memory management

- **Improved**: `SmartCropService` documentation and structure
  - Added comprehensive method documentation
  - Type hints for Intervention Image 3.x interfaces
  - Better comments explaining algorithm choices

- **Updated**: Cache operations to use consistent patterns
  - All `Cache::remember()` calls use tags
  - All `Cache::put()` calls use tags
  - All `Cache::forget()` calls use tags
  - Uniform TTL across all cache layers

### Fixed
- Cache invalidation now only affects thumbnails, not entire application cache
- Memory leaks with Intervention Image 2.x (automatic cleanup in 3.x)
- Inconsistent cache TTL values across different methods
- Missing type hints on public methods

### Removed
- Duplicate code from `ThumbnailService` and `GenerateThumbnailJob`
- Deprecated `ImageManagerStatic` usage
- Unused helper methods (now in `ThumbnailGenerator`)

### Documentation
- Added comprehensive AVIF format support documentation
- Format comparison table (AVIF vs WebP vs JPEG vs PNG)
- AVIF setup and configuration guide
- Updated requirements to highlight ImageMagick for AVIF
- Added comprehensive upgrade guide in README
- Created `SMART_CROP_ROADMAP.md` for future enhancements
- Added "What's New" section highlighting latest features
- Updated requirements section with accurate version info
- Added performance optimization tips
- Improved troubleshooting section

### Developer Experience
- Complete PHPDoc comments on all public methods
- English comments for international contributors
- Better code organization with utility classes
- Easier to test with static helper methods

## Previous Versions

### Version History
- See git commits for detailed history of previous versions
- Package initially released with Intervention Image 2.x support

---

## Upgrade Instructions

### From Previous Version to Latest

1. **Update Dependencies**:
   ```bash
   composer update askancy/laravel-smart-thumbnails
   ```

2. **Clear Caches** (optional but recommended):
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

3. **Verify Installation**:
   ```bash
   php artisan tinker
   >>> Thumbnail::validateConfiguration()
   ```

4. **Optional - Enable Cache Tagging**:
   Configure Redis or Memcached as your cache driver for optimal performance.

See the [Upgrade Guide](README.md#-upgrade-guide) in README for detailed instructions.

---

## Maintenance

This changelog is maintained by the package maintainers. For the latest changes, see the [Releases](https://github.com/askancy/laravel-smart-thumbnails/releases) page.

**Note**: Dates will be added when versions are officially released.
