<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Support\ImageCapabilities;
use Gadya\Cms\Tests\TestCase;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class ImageDriverTest extends TestCase
{
    public function test_the_pipeline_has_a_driver_whatever_the_server_has(): void
    {
        $capabilities = app(ImageCapabilities::class);

        $this->assertInstanceOf(
            $capabilities->hasImagick() ? ImagickDriver::class : GdDriver::class,
            $capabilities->driver(),
        );
        $this->assertContains($capabilities->driverName(), ['Imagick', 'GD']);
        $this->assertTrue($capabilities->supportsWebp(), 'CI and every sane host can write WebP; the doctor command reports it when one cannot.');
    }

    public function test_the_doctor_reports_the_driver(): void
    {
        $this->artisan('gadya-cms:doctor')->expectsOutputToContain('Image driver');
    }
}
