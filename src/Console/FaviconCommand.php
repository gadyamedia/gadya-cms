<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Brand\Favicon;
use Illuminate\Console\Command;

/**
 * Draws the browser-tab icon, or says why it did not.
 */
class FaviconCommand extends Command
{
    protected $signature = 'gadya-cms:favicon {--forget : Throw away what was drawn and draw it again}';

    protected $description = 'Draw the site\'s browser-tab icon from its logo';

    public function handle(Favicon $favicon): int
    {
        if ($this->option('forget')) {
            $favicon->forget();
        }

        if (! $favicon->enabled()) {
            $this->components->warn('brand.favicon is off, so the site serves no icon.');

            return self::SUCCESS;
        }

        if ($favicon->siteHasItsOwn()) {
            $this->components->info('The site has its own favicon in public/, which is served before Laravel is asked. Nothing to draw.');

            return self::SUCCESS;
        }

        $described = $favicon->describe();

        if (! $described['drawn']) {
            $this->components->error('The icon could not be drawn. Check that the image driver is installed with `gadya-cms:doctor`.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Drawn from the %s (%s at 32px). Served at /favicon.ico, /favicon-32.png, /apple-touch-icon.png and /site.webmanifest.',
            $described['from'] === 'logo' ? 'site logo' : "business's initials",
            $this->bytes($described['bytes']),
        ));

        if ($described['blank']) {
            $this->components->error('What was drawn is one flat colour, so nothing legible came out. Set brand.favicon_source to a square mark, or put a favicon.ico in public/.');

            return self::FAILURE;
        }

        if ($described['from'] === 'initials') {
            $this->components->warn('There is no logo to draw from. Upload one under Look & feel, or set brand.favicon_source.');
        }

        return self::SUCCESS;
    }

    private function bytes(int $bytes): string
    {
        return $bytes < 1024 ? $bytes.' B' : round($bytes / 1024, 1).' KB';
    }
}
