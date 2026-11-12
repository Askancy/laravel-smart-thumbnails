<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Feature;

use Askancy\LaravelSmartThumbnails\Services\SmartCropService;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Support\GenerationLock;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailGenerator;
use Askancy\LaravelSmartThumbnails\Support\ThumbnailPublisher;
use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\Attributes\DataProvider;

class ConcurrentGenerationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config(['thumbnails.generation_lock_wait' => 0]);
        Storage::disk('local')->put('source.png', ThumbnailGenerator::createImageManager()->create(200, 100)->fill('#ff0000')->toPng()->toString());
    }

    public function test_a_busy_generation_does_not_write_and_can_be_retried_after_release(): void
    {
        $service = (new ThumbnailService(new SmartCropService()))->set('test')->src('source.png', 'local')->strict();
        $path = $service->path();
        $lock = Cache::store()->getStore()->lock(GenerationLock::name('local', $path), 300);
        $this->assertTrue($lock->get());
        try {
            try {
                $service->url();
                $this->fail('A busy thumbnail must wait or time out.');
            } catch (LockTimeoutException $e) {
                $this->assertSame([], Storage::disk('local')->allFiles('test-crops'));
            }
        } finally {
            $lock->release();
        }
        $service->url();
        Storage::disk('local')->assertExists($path);
    }

    public function test_failed_generation_releases_its_lock(): void
    {
        $service = new class (new SmartCropService()) extends ThumbnailService {
            protected function generateThumbnailSafe(array $config, string $thumbnailPath, Lock $lock, ?string $variant = null): void
            {
                throw new \RuntimeException('Deliberate render failure.');
            }
        };
        $service->set('test')->src('source.png', 'local')->strict();
        $path = $service->path();
        try {
            $service->url();
            $this->fail('The render must fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate render failure.', $e->getMessage());
        }
        $this->assertTrue(GenerationLock::run('local', $path, fn ($lock) => true));
    }

    public function test_lost_lock_cannot_overwrite_an_existing_output(): void
    {
        Storage::disk('local')->put('output.png', 'previous-complete-file');
        $lock = Cache::store()->getStore()->lock(GenerationLock::name('local', 'output.png'), 300);
        $this->assertTrue($lock->get());
        $lock->release();
        try {
            ThumbnailPublisher::publish('local', 'output.png', 'new', $lock);
            $this->fail('A writer must still own its lock.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('lock expired', $e->getMessage());
        }
        $this->assertSame('previous-complete-file', Storage::disk('local')->get('output.png'));
    }

    public function test_failed_publication_preserves_the_old_file_and_removes_the_temporary_file(): void
    {
        $real = Storage::disk('local');
        $real->put('outputs/result.png', 'previous-complete-file');
        $adapter = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $adapter->shouldReceive('exists')->andReturnUsing(fn ($path) => $real->exists($path));
        $adapter->shouldReceive('put')->once()->andReturnUsing(fn ($path, $binary) => $real->put($path, $binary));
        $adapter->shouldReceive('move')->once()->andReturnUsing(function ($temporary, $target) use ($real) {
            $this->assertSame('new-complete-file', $real->get($temporary));
            $this->assertSame('previous-complete-file', $real->get($target));
            return false;
        });
        $adapter->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $real->delete($path));
        Storage::shouldReceive('disk')->with('local')->andReturn($adapter);
        try {
            GenerationLock::run('local', 'outputs/result.png', function ($lock) {
                ThumbnailPublisher::publish('local', 'outputs/result.png', 'new-complete-file', $lock);
            });
            $this->fail('Publication must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Could not publish', $e->getMessage());
        }
        $this->assertSame(['outputs/result.png'], $real->allFiles('outputs'));
        $this->assertSame('previous-complete-file', $real->get('outputs/result.png'));
    }

    public static function identityModes(): array
    {
        return [['content'], ['path']];
    }

    #[DataProvider('identityModes')]
    public function test_independent_web_and_queue_processes_render_once_and_publish_a_complete_image(string $identity): void
    {
        $base = storage_path('framework/testing');
        $root = $base . '/concurrency-' . bin2hex(random_bytes(12));
        $files = $this->app['files'];
        $files->ensureDirectoryExists($root . '/storage');
        file_put_contents($root . '/storage/source.png', Storage::disk('local')->get('source.png'));
        $fixture = dirname(__DIR__) . '/fixtures/concurrent-thumbnail.php';
        $web = new Process([PHP_BINARY, $fixture, $root, 'web', $identity], timeout: 20);
        $queue = new Process([PHP_BINARY, $fixture, $root, 'queue', $identity], timeout: 20);
        try {
            $web->start();
            $queue->start();
            $deadline = microtime(true) + 15;
            while (!file_exists($root . '/ready-web') || !file_exists($root . '/ready-queue')) {
                if (microtime(true) > $deadline || !$web->isRunning() || !$queue->isRunning()) {
                    $this->fail('Workers could not start: ' . $web->getErrorOutput() . $queue->getErrorOutput());
                }
                usleep(10000);
            }
            file_put_contents($root . '/start', 'start');
            while ($web->isRunning() || $queue->isRunning()) {
                if (is_dir($root . '/storage/outputs')) {
                    foreach ($files->allFiles($root . '/storage/outputs') as $file) {
                        if ($file->getExtension() === 'png') {
                            $decoded = imagecreatefromstring(file_get_contents($file->getPathname()));
                            $this->assertInstanceOf(\GdImage::class, $decoded);
                            $this->assertSame(50, imagesx($decoded));
                            $this->assertSame(50, imagesy($decoded));
                            $this->assertSame(0xff0000, imagecolorat($decoded, 25, 25) & 0xffffff);
                        }
                    }
                }
                $web->checkTimeout();
                $queue->checkTimeout();
                usleep(10000);
            }
            $this->assertSame(0, $web->getExitCode(), $web->getErrorOutput());
            $this->assertSame(0, $queue->getExitCode(), $queue->getErrorOutput());
            $this->assertSame("render\n", file_get_contents($root . '/render-count'));
            $outputs = $files->allFiles($root . '/storage/outputs');
            $this->assertCount(1, $outputs);
            $this->assertSame('png', $outputs[0]->getExtension());
        } finally {
            if ($web->isRunning()) {
                $web->stop();
            }
            if ($queue->isRunning()) {
                $queue->stop();
            }
            // Verify the absolute target is this test's directory before recursive cleanup on Windows.
            $this->assertStringStartsWith(realpath($base) . DIRECTORY_SEPARATOR, realpath($root));
            $files->deleteDirectory($root);
        }
    }
}
