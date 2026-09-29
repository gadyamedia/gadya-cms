<?php

namespace Gadya\Cms\Localisation;

use Illuminate\Support\Arr;

/**
 * Which strings in a piece of content are words a person reads, and so
 * worth translating, and which are machinery: a slug, a photo's filename,
 * a colour, a link, a date, a phone number.
 *
 * The answer is by key first (a `slug` is never translated, whatever it
 * says) and then by the look of the value (a string that is an address or
 * a filename is left alone wherever it sits).
 */
class TranslatableText
{
    /**
     * Keys whose values are never words, wherever they appear.
     *
     * @var list<string>
     */
    public const SKIPPED_KEYS = [
        'slug', 'type', 'status', 'key', 'id', 'uuid', 'image', 'hero_image', 'images', 'og_image', 'photo',
        'canonical', 'noindex', 'publish_at', 'unpublish_at', 'location', 'position', 'map_query',
        'url', 'href', 'link', 'icon', 'side', 'highlight', 'email', 'phone', 'telephone', 'color', 'colour',
        'embed', 'video', 'map', 'target', 'form', 'layout', 'variant', 'style', 'class', 'anchor',
        'lat', 'lng', 'latitude', 'longitude', 'price', 'booking_url',
        /* A built form's machinery: what is checked, when it shows, how wide, what it starts as. */
        'rules', 'logic', 'width', 'default',
    ];

    /**
     * Every translatable string in a value, keyed by its dot path inside
     * it. A value that is itself a single string answers under ''.
     *
     * @return array<string, string>
     */
    public function leaves(mixed $value): array
    {
        if (is_string($value)) {
            return $this->isWords($value) ? ['' => $value] : [];
        }

        if (! is_array($value)) {
            return [];
        }

        $leaves = [];

        foreach (Arr::dot($value) as $path => $leaf) {
            if (! is_string($leaf) || ! $this->isWords($leaf) || $this->isSkippedPath((string) $path)) {
                continue;
            }

            $leaves[(string) $path] = $leaf;
        }

        return $leaves;
    }

    /**
     * A fingerprint of the words in a piece of content, so a translation
     * can tell when the original has changed underneath it.
     *
     * @param  array<string, string>  $leaves
     */
    public function hash(array $leaves): string
    {
        ksort($leaves);

        return hash('sha256', (string) json_encode($leaves, JSON_UNESCAPED_UNICODE));
    }

    public function isSkippedPath(string $path): bool
    {
        foreach (explode('.', $path) as $segment) {
            if (in_array($segment, self::SKIPPED_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a string reads as words rather than as an address, a
     * filename, a colour, a date or a number.
     */
    public function isWords(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || preg_match_all('/\p{L}/u', $value) < 2) {
            return false;
        }

        $patterns = [
            '#^(https?:)?//\S+$#i',
            '#^(mailto|tel):\S+$#i',
            '#^/[^\s]*$#',
            '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
            '/^\S+\.(webp|jpe?g|png|gif|svg|avif|pdf|mp4|webm)$/i',
            '/^#[0-9a-f]{3,8}$/i',
            '/^\d{4}-\d{2}-\d{2}([ T][\d:.]+Z?)?$/',
            '/^[a-z0-9]+(?:-[a-z0-9]+)+$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return false;
            }
        }

        return true;
    }
}
