<?php

namespace Gadya\Cms\Support;

use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Reads a photo and writes WebP through Intervention Image 3 or 4, so a
 * site that already needs version 4 (Laravel 13's Image facade does) can
 * still install the package. Version 4 renamed read() to decodeBinary()
 * and moved the per-format encoders behind encodeUsingFormat().
 */
class Images
{
    public function __construct(private readonly ImageCapabilities $capabilities) {}

    public function read(string $binary): ImageInterface
    {
        $manager = $this->manager();

        return method_exists($manager, 'decodeBinary') ? $manager->decodeBinary($binary) : $manager->read($binary);
    }

    public function webp(ImageInterface $image, int $quality): string
    {
        return method_exists($image, 'encodeUsingFormat')
            ? (string) $image->encodeUsingFormat(Format::WEBP, quality: $quality)
            : (string) $image->toWebp($quality);
    }

    /** The same, as a PNG, which is what an icon wants. */
    public function png(ImageInterface $image): string
    {
        return method_exists($image, 'encodeUsingFormat')
            ? (string) $image->encodeUsingFormat(Format::PNG)
            : (string) $image->encode(new PngEncoder);
    }

    private function manager(): ImageManager
    {
        return once(fn (): ImageManager => new ImageManager($this->capabilities->driver(), strip: true));
    }
}
