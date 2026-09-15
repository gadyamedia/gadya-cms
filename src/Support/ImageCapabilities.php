<?php

namespace Gadya\Cms\Support;

use Imagick;
use Symfony\Component\Process\ExecutableFinder;

class ImageCapabilities
{
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
