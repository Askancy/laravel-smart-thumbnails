<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Support\CacheHelper;
use Illuminate\Support\Facades\Cache;

class CacheHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test */
    public function it_detects_cache_driver_tagging_support()
    {
        // Test with array driver (supports tagging)
        config(['cache.default' => 'array']);
        $this->assertTrue(CacheHelper::supportsTagging());
    }

    /** @test */
    public function it_returns_driver_info()
    {
        $info = CacheHelper::getDriverInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('driver', $info);
        $this->assertArrayHasKey('supports_tagging', $info);
        $this->assertArrayHasKey('recommendation', $info);
        $this->assertArrayHasKey('supported_drivers', $info);
    }

    /** @test */
    public function it_can_remember_values_with_tags()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'test-key';
        $value = 'test-value';
        $ttl = 60;

        $result = CacheHelper::remember($tags, $key, $ttl, function () use ($value) {
            return $value;
        });

        $this->assertEquals($value, $result);
    }

    /** @test */
    public function it_caches_values_on_subsequent_calls()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'test-key-cached';
        $ttl = 60;
        $callCount = 0;

        // First call
        $result1 = CacheHelper::remember($tags, $key, $ttl, function () use (&$callCount) {
            $callCount++;
            return 'computed-value';
        });

        // Second call should use cache
        $result2 = CacheHelper::remember($tags, $key, $ttl, function () use (&$callCount) {
            $callCount++;
            return 'computed-value';
        });

        $this->assertEquals('computed-value', $result1);
        $this->assertEquals('computed-value', $result2);
        $this->assertEquals(1, $callCount, 'Callback should only be called once');
    }

    /** @test */
    public function it_can_put_values_with_tags()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'put-test-key';
        $value = 'put-test-value';
        $ttl = 60;

        CacheHelper::put($tags, $key, $value, $ttl);

        $retrieved = CacheHelper::get($tags, $key);
        $this->assertEquals($value, $retrieved);
    }

    /** @test */
    public function it_can_get_values_with_tags()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'get-test-key';
        $value = 'get-test-value';
        $ttl = 60;

        CacheHelper::put($tags, $key, $value, $ttl);
        $retrieved = CacheHelper::get($tags, $key);

        $this->assertEquals($value, $retrieved);
    }

    /** @test */
    public function it_returns_null_for_non_existent_keys()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'non-existent-key';

        $retrieved = CacheHelper::get($tags, $key);

        $this->assertNull($retrieved);
    }

    /** @test */
    public function it_can_forget_values_with_tags()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'forget-test-key';
        $value = 'forget-test-value';
        $ttl = 60;

        CacheHelper::put($tags, $key, $value, $ttl);
        $this->assertEquals($value, CacheHelper::get($tags, $key));

        CacheHelper::forget($tags, $key);
        $this->assertNull(CacheHelper::get($tags, $key));
    }

    /** @test */
    public function it_can_check_if_key_exists()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'exists-test-key';
        $value = 'exists-test-value';
        $ttl = 60;

        $this->assertFalse(CacheHelper::has($tags, $key));

        CacheHelper::put($tags, $key, $value, $ttl);
        $this->assertTrue(CacheHelper::has($tags, $key));

        CacheHelper::forget($tags, $key);
        $this->assertFalse(CacheHelper::has($tags, $key));
    }

    /** @test */
    public function it_can_flush_tagged_cache()
    {
        $tags = ['thumbnails', 'test'];
        $key1 = 'flush-test-key-1';
        $key2 = 'flush-test-key-2';
        $ttl = 60;

        CacheHelper::put($tags, $key1, 'value1', $ttl);
        CacheHelper::put($tags, $key2, 'value2', $ttl);

        $this->assertTrue(CacheHelper::has($tags, $key1));
        $this->assertTrue(CacheHelper::has($tags, $key2));

        CacheHelper::flush($tags);

        // Note: flush behavior depends on driver
        // With array driver (supports tagging), both should be cleared
        // With file driver (no tagging), it logs warning but doesn't clear
        if (CacheHelper::supportsTagging()) {
            $this->assertFalse(CacheHelper::has($tags, $key1));
            $this->assertFalse(CacheHelper::has($tags, $key2));
        }
    }

    /** @test */
    public function it_handles_multiple_tags()
    {
        $tags1 = ['thumbnails', 'preset1'];
        $tags2 = ['thumbnails', 'preset2'];
        $key = 'multi-tag-key';
        $ttl = 60;

        CacheHelper::put($tags1, $key, 'value1', $ttl);
        CacheHelper::put($tags2, $key, 'value2', $ttl);

        $value1 = CacheHelper::get($tags1, $key);
        $value2 = CacheHelper::get($tags2, $key);

        // Different tags should store different values
        $this->assertEquals('value1', $value1);
        $this->assertEquals('value2', $value2);
    }

    /** @test */
    public function it_works_with_file_cache_driver()
    {
        // Simulate file driver (no tagging support)
        config(['cache.default' => 'file']);

        $tags = ['thumbnails', 'test'];
        $key = 'file-driver-key';
        $value = 'file-driver-value';
        $ttl = 60;

        // Should still work with fallback
        CacheHelper::put($tags, $key, $value, $ttl);
        $retrieved = CacheHelper::get($tags, $key);

        $this->assertEquals($value, $retrieved);
    }

    /** @test */
    public function it_prefixes_keys_for_non_tagging_drivers()
    {
        // With non-tagging drivers, keys should be prefixed to avoid collisions
        config(['cache.default' => 'file']);

        $tags = ['thumbnails', 'test'];
        $key = 'prefix-test';
        $value = 'prefixed-value';
        $ttl = 60;

        CacheHelper::put($tags, $key, $value, $ttl);

        // The actual key should be prefixed with first tag
        // This is implementation detail but important for fallback behavior
        $prefixedKey = 'thumbnails:' . $key;
        $directValue = Cache::get($prefixedKey);

        $this->assertEquals($value, $directValue);
    }

    /** @test */
    public function remember_method_does_not_call_callback_if_cached()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'remember-no-call';
        $ttl = 60;
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return 'result';
        };

        // First call
        CacheHelper::remember($tags, $key, $ttl, $callback);
        $this->assertEquals(1, $callCount);

        // Second call should not invoke callback
        CacheHelper::remember($tags, $key, $ttl, $callback);
        $this->assertEquals(1, $callCount);
    }

    /** @test */
    public function it_handles_complex_values()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'complex-value';
        $ttl = 60;

        $complexValue = [
            'string' => 'value',
            'number' => 123,
            'array' => [1, 2, 3],
            'nested' => [
                'key' => 'value'
            ],
            'boolean' => true,
            'null' => null,
        ];

        CacheHelper::put($tags, $key, $complexValue, $ttl);
        $retrieved = CacheHelper::get($tags, $key);

        $this->assertEquals($complexValue, $retrieved);
    }

    /** @test */
    public function it_respects_ttl()
    {
        $tags = ['thumbnails', 'test'];
        $key = 'ttl-test';
        $value = 'ttl-value';
        $ttl = 1; // 1 second

        CacheHelper::put($tags, $key, $value, $ttl);
        $this->assertTrue(CacheHelper::has($tags, $key));

        // Wait for cache to expire
        sleep(2);

        $this->assertFalse(CacheHelper::has($tags, $key));
    }

    /** @test */
    public function flush_logs_warning_for_non_tagging_drivers()
    {
        config(['cache.default' => 'file']);

        // Capture log output
        $logged = false;
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with(
                'Cache flush requested but current driver does not support tagging',
                \Mockery::type('array')
            )
            ->andReturnUsing(function () use (&$logged) {
                $logged = true;
            });

        CacheHelper::flush(['thumbnails']);

        $this->assertTrue($logged, 'Warning should be logged for non-tagging drivers');
    }
}
