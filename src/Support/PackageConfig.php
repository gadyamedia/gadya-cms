<?php

namespace Gadya\Cms\Support;

/**
 * How a site's `config/gadya-cms.php` and the package's defaults become one
 * config: the site's file wins wherever it says something, and a key a
 * release adds - however deep - takes the package's default until the site
 * sets it. So a site's published config never has to be brought up to date
 * for the site to keep working.
 *
 * Settings merge key by key, all the way down. Lists (`locales.enabled`,
 * `media.variants`, `seo.robots_disallow`) are the site's whole answer and
 * are never mixed with the package's; an empty array is a list too. So are
 * the maps whose entries the site owns (its roles, forms, fonts, editable
 * paths): the package's entries there are only examples, and merging them
 * in would add a role, a form or an editable field the site never had.
 */
final class PackageConfig
{
    /** Maps a site defines entry by entry: taken whole from the site when it sets them. */
    public const SITE_OWNED = [
        'editable_fields',
        'globals',
        'navigation.menus',
        'pages.types',
        'pages.content_fields',
        'users.roles',
        'forms.forms',
        'forms.builder.destinations',
        'forms.builder.attribution.site',
        'fonts.display',
        'fonts.sans',
        'menus.dietary',
        'seo.content_signals',
    ];

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $site
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $site, string $path = ''): array
    {
        $merged = $defaults;

        foreach ($site as $key => $value) {
            $keyPath = ltrim($path.'.'.$key, '.');

            $merged[$key] = self::mergesKeyByKey($defaults[$key] ?? null, $value, $keyPath)
                ? self::merge($defaults[$key], $value, $keyPath)
                : $value;
        }

        return $merged;
    }

    private static function mergesKeyByKey(mixed $default, mixed $value, string $path): bool
    {
        return is_array($default) && is_array($value)
            && ! array_is_list($default) && ! array_is_list($value)
            && ! in_array($path, self::SITE_OWNED, true);
    }
}
