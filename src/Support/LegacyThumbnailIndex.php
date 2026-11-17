<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Storage;

/** Durable, private pointers. No bucket listing or source reads on the request path. */
class LegacyThumbnailIndex
{
    private static function key(string $identity, string $preset): string
    {
        return ThumbnailManifest::root() . '-adoptions/' . hash('sha256', $preset . ':' . $identity) . '.json';
    }

    private static function identity(string $source, string $disk, array $config, ?string $variant): string
    {
        $destination = [];
        $name = $config['destination']['disk'];
        while (!array_key_exists($name, $destination)) {
            $destination[$name] = config('filesystems.disks.' . $name, []);
            if (($destination[$name]['driver'] ?? null) !== 'scoped') {
                break;
            }
            $name = $destination[$name]['disk'] ?? $name;
        }
        return hash('sha256', serialize([ThumbnailIdentity::fingerprint($source, $disk, $config, $variant, ''), $destination]));
    }

    public static function resolve(string $source, string $disk, array $config, ?string $variant, string $preset): ?array
    {
        if (ThumbnailIdentity::usesContentHash($disk, $config)) {
            return null;
        }
        $identity = self::identity($source, $disk, $config, $variant);
        $storage = Storage::disk(ThumbnailManifest::disk());
        $key = self::key($identity, $preset);
        if (!$storage->exists($key)) {
            return null;
        }
        $pointer = json_decode($storage->get($key), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($pointer) || ($pointer['identity'] ?? null) !== $identity
            || !is_string($pointer['manifest'] ?? null)) {
            throw new \RuntimeException('Invalid legacy thumbnail pointer.');
        }
        if (!$storage->exists($pointer['manifest'])) {
            return null; // Explicit invalidation removed the ownership record.
        }
        $record = ThumbnailManifest::read($pointer['manifest']);
        if ($record['source'] !== $source || $record['source_disk'] !== $disk
            || $record['preset'] !== $preset || $record['variant'] !== $variant
            || $record['disk'] !== $config['destination']['disk']
            || $record['destination_root'] !== trim($config['destination']['path'], '/')
            || $record['identity'] !== ($pointer['legacy_identity'] ?? null)) {
            throw new \RuntimeException('Legacy thumbnail pointer does not match its request.');
        }
        return $record;
    }

    /** Caller holds both source and output locks. Publish last, after all verification. */
    public static function adopt(array $record, array $config): void
    {
        $identity = self::identity($record['source'], $record['source_disk'], $config, $record['variant']);
        $key = self::key($identity, $record['preset']);
        $pointer = ['identity' => $identity, 'legacy_identity' => $record['identity'],
            'manifest' => ThumbnailManifest::path($record['disk'], $record['path'])];
        $storage = Storage::disk(ThumbnailManifest::disk());
        $temporary = $key . '.' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            if (!$storage->put($temporary, json_encode($pointer, JSON_THROW_ON_ERROR), ['visibility' => 'private'])
                || !$storage->move($temporary, $key)) {
                throw new \RuntimeException('Could not persist legacy thumbnail adoption.');
            }
            CacheHelper::flush([CacheHelper::sourceTag($record['source_disk'], $record['source'])]);
        } finally {
            if ($storage->exists($temporary)) {
                $storage->delete($temporary);
            }
        }
    }
}
