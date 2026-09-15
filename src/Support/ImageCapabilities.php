<?php

namespace Gadya\Cms\Support;

use Imagick;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\DriverInterface;
use Symfony\Component\Process\ExecutableFinder;

class ImageCapabilities
{
    /**
     * Imagick when the server has it - it handles HEIC and large photos
     * better - and GD otherwise, which every PHP build ships with. Either
     * way every upload still comes out as WebP; a host without Imagick
     * loses HEIC uploads, not the pipeline.
     */
    public function driver(): DriverInterface
    {
        return $this->hasImagick() ? new ImagickDriver : new GdDriver;
    }

    public function driverName(): string
    {
        return $this->hasImagick() ? 'Imagick' : 'GD';
    }

    public function hasImagick(): bool
    {
        return class_exists(Imagick::class);
    }

    public function supportsWebp(): bool
    {
        return $this->hasImagick()
            ? Imagick::queryFormats('WEBP') !== []
            : (function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false));
    }

    public function supportsHeic(): bool
    {
        return once(fn (): bool => class_exists(Imagick::class) && Imagick::queryFormats('HEI*') !== []);
    }

    /**
     * @return list<string>
     */
    public function missingBinaries(): array
    {
        return once(function (): array {
            $finder = new ExecutableFinder;

            return array_values(array_filter(
                ['jpegoptim', 'pngquant', 'cwebp'],
                fn (string $binary): bool => $finder->find($binary) === null,
            ));
        });
    }

    /**
     * @return list<string>
     */
    public function acceptedExtensions(): array
    {
        return $this->supportsHeic()
            ? ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif']
            : ['jpg', 'jpeg', 'png', 'webp'];
    }
}
