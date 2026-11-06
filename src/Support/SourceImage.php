<?php

namespace Askancy\LaravelSmartThumbnails\Support;

use Illuminate\Support\Facades\Storage;

/** Read and validate a bounded source before decoding or consulting thumbnail caches. */
class SourceImage
{
    public static function read(string $path, string $disk): array
    {
        $storage = Storage::disk($disk);
        if (!$storage->exists($path)) {
            throw new \RuntimeException("Source image not found: {$path}");
        }
        ThumbnailGenerator::validateImageContent($path);
        $limit = (int) config('thumbnails.max_file_size', 10 * 1024 * 1024);
        if ($limit > 0 && $storage->size($path) > $limit) {
            throw new \RuntimeException("Source image exceeds max_file_size: {$path}");
        }
        $stream = $storage->readStream($path);
        if (!is_resource($stream)) {
            throw new \RuntimeException("Cannot read source image: {$path}");
        }
        try {
            $content = $limit > 0 ? stream_get_contents($stream, $limit + 1) : stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        if ($content === false || $content === '') {
            throw new \RuntimeException("Source image is empty or unreadable: {$path}");
        }
        if ($limit > 0 && strlen($content) > $limit) {
            throw new \RuntimeException("Source image exceeds max_file_size: {$path}");
        }
        ThumbnailGenerator::validateImageContent($path, $content);

        return ['content' => $content, 'hash' => hash('sha256', $content)];
    }
}
