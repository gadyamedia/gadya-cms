<?php

namespace Gadya\Cms\Brand;

use Gadya\Cms\Filament\GadyaCmsPlugin;
use Illuminate\Contracts\View\View;

/**
 * The "built by Gadya Media" badge for a site's footer, in the site's own
 * ink: the colour comes from the brand unless the site names one, and the
 * logo is recoloured to match with a filter worked out for that colour.
 */
class BuiltBy
{
    /** The badge's own colour, for a site whose ink is not a hex colour. */
    public const GADYA_NAVY = '#29376a';

    /**
     * @param  array{color?: string, filter?: string, logo_height?: int, align?: string}  $options
     */
    public function render(array $options = []): string
    {
        if (! config('gadya-cms.built_by.enabled', true)) {
            return '';
        }

        return $this->view($options)->render();
    }

    /**
     * @param  array{color?: string, filter?: string, logo_height?: int, align?: string}  $options
     */
    public function view(array $options = []): View
    {
        $wanted = (string) ($options['color'] ?? config('gadya-cms.built_by.color') ?? GadyaCmsPlugin::brandTokens()['ink']);
        $color = rescue(fn (): string => ColorFilter::normalise($wanted), self::GADYA_NAVY, report: false);
        $align = (string) ($options['align'] ?? config('gadya-cms.built_by.align', 'end'));

        return view('gadya-cms::brand.built-by', [
            'color' => $color,
            'filter' => (string) ($options['filter'] ?? config('gadya-cms.built_by.filter') ?? ColorFilter::for($color)),
            'logoHeight' => (int) ($options['logo_height'] ?? config('gadya-cms.built_by.logo_height', 28)),
            'justify' => match ($align) {
                'start' => 'flex-start',
                'center' => 'center',
                default => 'flex-end',
            },
        ]);
    }
}
