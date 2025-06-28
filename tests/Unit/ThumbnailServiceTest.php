<?php

namespace Askancy\LaravelSmartThumbnails\Tests\Unit;

use Askancy\LaravelSmartThumbnails\Tests\TestCase;
use Askancy\LaravelSmartThumbnails\Services\ThumbnailService;
use Askancy\LaravelSmartThumbnails\Services\SmartCropService;

class ThumbnailServiceTest extends TestCase
{
    public function test_package_loads_correctly()
    {
        $this->assertTrue(true);
    }

    public function test_can_create_thumbnail_service()
    {
        $smartCropService = new SmartCropService();
        $thumbnailService = new ThumbnailService($smartCropService);
        
        $this->assertInstanceOf(ThumbnailService::class, $thumbnailService);
    }

    public function test_can_set_configuration()
    {
        $smartCropService = new SmartCropService();
        $thumbnailService = new ThumbnailService($smartCropService);
        
        $service = $thumbnailService->set('test');
        
        $this->assertInstanceOf(ThumbnailService::class, $service);
    }

    public function test_throws_exception_for_invalid_configuration()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Thumbnail configuration 'invalid' not found");
        
        $smartCropService = new SmartCropService();
        $thumbnailService = new ThumbnailService($smartCropService);
        $thumbnailService->set('invalid');
    }
}
