<?php

namespace Gadya\Cms\Menus;

/**
 * The dietary and allergen marks an item can carry, from
 * `gadya-cms.menus.dietary`. A mark with a schema.org diet is told to
 * search engines as `suitableForDiet`; an allergen warning ("contains
 * nuts") is for people only, because schema.org has no word for it.
 */
class Dietary
{
    /**
     * @return array<string, array{label: string, short: string, schema: string|null}>
     */
    public static function all(): array
    {
        $tags = [];

        foreach ((array) config('gadya-cms.menus.dietary', []) as $key => $tag) {
            if (! is_string($key) || ! is_array($tag)) {
                continue;
            }

            $tags[$key] = [
                'label' => (string) ($tag['label'] ?? $key),
                'short' => (string) ($tag['short'] ?? $tag['label'] ?? $key),
                'schema' => isset($tag['schema']) ? (string) $tag['schema'] : null,
            ];
        }

        return $tags;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $tag): string => $tag['label'], static::all());
    }

    /**
     * @param  list<string>|null  $keys
     * @return array<string, array{label: string, short: string, schema: string|null}>
     */
    public static function for(?array $keys): array
    {
        return array_intersect_key(static::all(), array_flip($keys ?? []));
    }
}
