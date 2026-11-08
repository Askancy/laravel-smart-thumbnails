<?php

namespace Askancy\LaravelSmartThumbnails\Support;

class ThumbnailIdentity
{
    /** Resolve global rendering defaults once, including for queued jobs. */
    public static function settings(array $config): array
    {
        foreach ([
            'format' => ['default_format', 'jpg'], 'quality' => ['default_quality', 85],
            'smart_crop_enabled' => ['enable_smart_crop', true], 'crop_mode' => ['crop_mode', null],
            'dont_crop_background' => ['dont_crop_background', 'gradient'],
            'allow_upscale' => ['allow_upscale', true], 'webp_lossless' => ['webp_lossless', false],
            'enable_progressive_jpeg' => ['enable_progressive_jpeg', true],
            'png_compression' => ['png_compression', 6],
            'allow_mismatched_extensions' => ['allow_mismatched_extensions', false],
        ] as $key => [$global, $default]) {
            $config[$key] ??= config('thumbnails.' . $global, $default);
        }
        $config['format'] = strtolower($config['format']);
        if (!in_array($config['format'], ['jpg', 'jpeg', 'png', 'webp', 'avif'], true)) {
            throw new \InvalidArgumentException("Unsupported thumbnail format '{$config['format']}'.");
        }
        if (!isset($config['smartcrop']) || !preg_match('/^[1-9]\d*x[1-9]\d*$/', $config['smartcrop'])) {
            throw new \InvalidArgumentException('Thumbnail dimensions must be positive integers, e.g. 300x200.');
        }
        $quality = filter_var($config['quality'], FILTER_VALIDATE_INT);
        if ($quality === false || $quality < 0 || $quality > 100) {
            throw new \InvalidArgumentException('Thumbnail quality must be between 0 and 100.');
        }
        $config['quality'] = $quality;
        $compression = filter_var($config['png_compression'], FILTER_VALIDATE_INT);
        if ($compression === false || $compression < 0 || $compression > 9) {
            throw new \InvalidArgumentException('PNG compression must be between 0 and 9.');
        }
        $config['png_compression'] = $compression;
        if (!isset($config['destination']['disk'], $config['destination']['path'])
            || !is_string($config['destination']['disk']) || $config['destination']['disk'] === ''
            || !is_string($config['destination']['path'])) {
            throw new \InvalidArgumentException('A destination disk and path must be configured.');
        }
        // Also validate explicit modes before computing a path or queuing a job.
        ThumbnailGenerator::renderingSuffix($config);

        return $config;
    }

    public static function fingerprint(string $source, string $disk, array $config, ?string $variant, string $sourceHash): string
    {
        $config = self::settings($config);
        $render = $config;
        unset($render['variants'], $render['allow_mismatched_extensions']);
        return hash('sha256', serialize(['v3', $disk, str_replace('\\', '/', $source), $sourceHash, $render,
            $variant, config('thumbnails.intervention_driver', 'gd')]));
    }

    public static function path(string $source, string $disk, array $config, ?string $variant, string $sourceHash): string
    {
        $config = self::settings($config);
        $identity = self::fingerprint($source, $disk, $config, $variant, $sourceHash);
        $filename = pathinfo($source, PATHINFO_FILENAME);
        if (config('thumbnails.sanitize_filenames', true)) {
            $filename = ThumbnailGenerator::sanitizeFilename($filename);
        }
        $filename = $filename !== '' ? $filename : 'image';
        $suffix = str_replace('x', '_', $config['smartcrop']);
        if ($variant !== null) {
            $suffix .= '_' . ThumbnailGenerator::sanitizeFilename($variant);
        }
        $suffix .= ThumbnailGenerator::renderingSuffix($config);
        $strategy = $config['subdirectory_strategy'] ?? config('thumbnails.default_subdirectory_strategy', 'hash_prefix');
        $directory = ThumbnailGenerator::generateSubdirectory(in_array($strategy, ['hash_prefix', 'hash_levels'], true) ? $identity : $filename, $config);

        $base = trim($config['destination']['path'], '/');
        return ($base === '' ? '' : $base . '/') . $directory . substr($identity, 0, 32) . '/'
            . $filename . '_' . $suffix . '.' . $config['format'];
    }
}
