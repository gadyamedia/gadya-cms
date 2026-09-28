<?php

namespace Gadya\Cms\Forms\Builder;

/**
 * The script and the default stylesheet a built form brings with it.
 *
 * Inlined once per page rather than served as files, so a form works on
 * any site the moment it is placed - no build step, no published asset to
 * forget on deploy. Both are small; the stylesheet is optional, and every
 * rule in it sits under :where(), so anything the site writes wins.
 */
final class FormAssets
{
    public static function script(): string
    {
        return once(fn (): string => (string) @file_get_contents(dirname(__DIR__, 3).'/resources/js/forms.js'));
    }

    public static function styles(): string
    {
        return once(fn (): string => (string) @file_get_contents(dirname(__DIR__, 3).'/resources/css/forms.css'));
    }
}
