<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'gadya-cms:doctor';

    protected $description = 'Verify the server can process image uploads';

    public function handle(ImageCapabilities $capabilities): int
    {
        $missing = $capabilities->missingBinaries();

        $this->table(['Capability', 'Status'], [
            ['Image driver', $capabilities->driverName().($capabilities->hasImagick() ? '' : ' (Imagick missing; HEIC uploads are off)')],
            ['WebP output', $capabilities->supportsWebp() ? 'available' : 'MISSING'],
            ['HEIC decoding', $capabilities->supportsHeic() ? 'available' : 'MISSING'],
            ['Optimiser binaries', $missing === [] ? 'available' : 'MISSING: '.implode(', ', $missing)],
        ]);

        return ($missing === [] && $capabilities->supportsWebp()) ? self::SUCCESS : self::FAILURE;
    }
}
