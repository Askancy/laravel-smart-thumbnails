<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Storage;

/** Private ownership records; unknown files are never adopted by the pruner. */
class ThumbnailManifest
{
    /** Serialize generation and explicit invalidation, including across different variants. */
    public static function withSourceLock(string $source, string $disk, callable $callback)
    {
        return GenerationLock::run($disk, '.source-lock/' . hash('sha256', $source), $callback);
    }

    /** Remove managed derivatives of this source; never read the original or delete unknown files. */
    public static function invalidateSource(string $source, string $disk): void
    {
        self::withSourceLock($source, $disk, function ($sourceLock) use ($source, $disk) {
            $index = Storage::disk(self::disk());
            foreach ($index->allFiles(self::root()) as $file) {
                if (!str_ends_with($file, '.json')) {
                    continue;
                }
                $record = self::read($file);
                if ($record['source'] !== $source || $record['source_disk'] !== $disk) {
                    continue;
                }
                GenerationLock::run($record['disk'], $record['path'], function ($lock) use ($record, $file, $index, $sourceLock) {
                    GenerationLock::assertOwned($sourceLock);
                    GenerationLock::assertOwned($lock);
                    $output = Storage::disk($record['disk']);
                    if ($output->exists($record['path']) && !$output->delete($record['path'])) {
                        throw new \RuntimeException('Could not invalidate the source thumbnail.');
                    }
                    if ($index->exists($file) && !$index->delete($file)) {
                        throw new \RuntimeException('Could not invalidate the thumbnail ownership record.');
                    }
                });
            }
            CacheHelper::flush([CacheHelper::sourceTag($disk, $source)]);
        });
    }

    public static function disk(): string
    {
        return config('thumbnails.manifest_disk', 'local');
    }

    public static function root(): string
    {
        $root = trim(config('thumbnails.manifest_path', 'smart-thumbnails/manifests'), '/');
        if ($root === '' || str_contains($root, '\\') || str_contains($root, ':') || preg_match('~(^|/)\.\.?(/|$)~', $root)) {
            throw new \RuntimeException('The thumbnail manifest path must be a non-empty relative directory.');
        }
        return $root;
    }

    public static function path(string $disk, string $path): string
    {
        $key = hash('sha256', $disk . ':' . $path);
        return self::root() . '/' . substr($key, 0, 2) . '/' . $key . '.json';
    }

    public static function record(string $source, string $sourceDisk, string $preset, ?string $variant, array $config, string $sourceHash): array
    {
        $adopted = LegacyThumbnailIndex::resolve($source, $sourceDisk, $config, $variant, $preset);
        return [
            'schema' => 1, 'source' => $source, 'source_disk' => $sourceDisk,
            'preset' => $preset, 'variant' => $variant,
            'identity' => $adopted['identity'] ?? ThumbnailIdentity::fingerprint($source, $sourceDisk, $config, $variant, $sourceHash),
            'destination_root' => trim($config['destination']['path'], '/'),
        ];
    }

    public static function write(string $disk, string $path, string $binary, array $record): void
    {
        // Explicit custom job destinations have no managed identity directory and remain untracked.
        if (basename(dirname($path)) !== substr($record['identity'], 0, 32)) {
            return;
        }
        $size = @getimagesizefromstring($binary);
        $record = array_merge($record, ['disk' => $disk, 'path' => $path,
            'output_hash' => hash('sha256', $binary), 'generated_at' => now()->getTimestamp()],
            $size !== false ? ['width' => $size[0], 'height' => $size[1]] : []);
        $storage = Storage::disk(self::disk());
        $target = self::path($disk, $path);
        $temporary = $target . '.' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            if (!$storage->put($temporary, json_encode($record, JSON_THROW_ON_ERROR), ['visibility' => 'private'])
                || !$storage->move($temporary, $target)) {
                throw new \RuntimeException('Could not persist thumbnail ownership metadata.');
            }
        } finally {
            if ($storage->exists($temporary)) {
                $storage->delete($temporary);
            }
        }
    }

    /** Output dimensions recorded at publication, so metadata lookups never download the derivative. */
    public static function dimensions(string $disk, string $path): ?array
    {
        $manifest = self::path($disk, $path);
        try {
            if (!Storage::disk(self::disk())->exists($manifest)) {
                return null;
            }
            $record = self::read($manifest);
        } catch (\Throwable $e) {
            return null;
        }
        $width = $record['width'] ?? null;
        $height = $record['height'] ?? null;
        return is_int($width) && is_int($height) && $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    /** Complete a record written without dimensions (4.3 adoptions) so later lookups need no storage read. */
    public static function rememberDimensions(string $disk, string $path, int $width, int $height): void
    {
        $manifest = self::path($disk, $path);
        $storage = Storage::disk(self::disk());
        try {
            if (!$storage->exists($manifest)) {
                return;
            }
            $record = self::read($manifest);
            if (isset($record['width'], $record['height'])) {
                return;
            }
            $record['width'] = $width;
            $record['height'] = $height;
            $temporary = $manifest . '.' . bin2hex(random_bytes(16)) . '.tmp';
            if ($storage->put($temporary, json_encode($record, JSON_THROW_ON_ERROR), ['visibility' => 'private'])) {
                $storage->move($temporary, $manifest);
            }
        } catch (\Throwable $e) {
            // Only an optimisation: the next lookup measures the header again.
        } finally {
            if (isset($temporary) && $storage->exists($temporary)) {
                $storage->delete($temporary);
            }
        }
    }

    public static function read(string $manifest): array
    {
        $binary = Storage::disk(self::disk())->get($manifest);
        if (!is_string($binary)) {
            throw new \RuntimeException('Thumbnail ownership metadata is missing.');
        }
        $record = json_decode($binary, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['schema'] ?? null) !== 1 || !is_int($record['generated_at'] ?? null)
            || !array_key_exists('variant', $record) || ($record['variant'] !== null && !is_string($record['variant']))) {
            throw new \RuntimeException('Invalid thumbnail ownership metadata.');
        }
        foreach (['source', 'source_disk', 'preset', 'disk', 'path', 'destination_root', 'identity', 'output_hash'] as $field) {
            if (!isset($record[$field]) || !is_string($record[$field])) {
                throw new \RuntimeException('Incomplete thumbnail ownership metadata.');
            }
        }
        $path = $record['path'];
        $root = $record['destination_root'];
        if (!preg_match('/^[a-f0-9]{64}$/', $record['identity']) || !preg_match('/^[a-f0-9]{64}$/', $record['output_hash'])
            || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('~(^|/)\.\.?(/|$)~', $path)
            || ($root !== '' && !str_starts_with($path, $root . '/'))
            || basename(dirname($path)) !== substr($record['identity'], 0, 32)
            || $manifest !== self::path($record['disk'], $path)) {
            throw new \RuntimeException('Thumbnail ownership metadata does not match its managed path.');
        }
        return $record;
    }
}
