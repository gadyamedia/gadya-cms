<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Console\Command;
use Imagick;

class DoctorCommand extends Command
{
    protected $signature = 'gadya-cms:doctor';

    protected $description = 'Verify the server can process image uploads';

    public function handle(ImageCapabilities $capabilities): int
    {
        $missing = $capabilities->missingBinaries();

        $this->table(['Capability', 'Status'], [
            ['Imagick extension', class_exists(Imagick::class) ? 'available' : 'MISSING'],
            ['HEIC decoding', $capabilities->supportsHeic() ? 'available' : 'MISSING'],
            ['Optimiser binaries', $missing === [] ? 'available' : 'MISSING: '.implode(', ', $missing)],
        ]);

        return ($missing === [] && class_exists(Imagick::class)) ? self::SUCCESS : self::FAILURE;
    }
}
