<?php

namespace Gadya\Cms\Content;

use Illuminate\Support\Arr;

/**
 * The colours and type the client may change, resolved against the
 * application's shipped defaults. Every value that comes back out of here
 * has been checked: a colour that is not a six-digit hex, or a font that is
 * not on the curated list, falls back to the default rather than reaching a
 * stylesheet.
 */
class SiteTheme
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    public function __construct(private readonly SiteContentRepository $repository) {}

    /**
     * @return array<string, string>
     */
    public function colors(): array
    {
        $defaults = $this->defaults()['colors'];
        $stored = Arr::get($this->effectiveTheme(), 'colors', $defaults);

        $safe = [];

        foreach ($defaults as $key => $default) {
            $candidate = $stored[$key] ?? null;

            $safe[$key] = is_string($candidate) && preg_match(self::COLOR_PATTERN, $candidate) === 1
                ? $candidate
                : $default;
        }

        return $safe;
    }

    /**
     * @return array<string, string>
     */
    public function fonts(): array
    {
        $defaults = $this->defaults()['fonts'];
        $stored = Arr::get($this->effectiveTheme(), 'fonts', $defaults);

        return [
            'display' => isset($this->catalogue('display')[$stored['display'] ?? '']) ? $stored['display'] : $defaults['display'],
            'sans' => isset($this->catalogue('sans')[$stored['sans'] ?? '']) ? $stored['sans'] : $defaults['sans'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function curatedDisplayFonts(): array
    {
        return $this->labels('display');
    }

    /**
     * @return array<string, string>
     */
    public function curatedSansFonts(): array
    {
        return $this->labels('sans');
    }

    /**
     * @return list<string>
     */
    public function allowedColorKeys(): array
    {
        return array_keys($this->defaults()['colors']);
    }

    /**
     * A stylesheet link for the chosen font, or null when it is the default
     * the site already loads for itself.
     */
    public function bunnyLinkFor(string $slot, string $fontName): ?string
    {
        $font = $this->fontDefinition($slot, $fontName);

        if ($font === null) {
            return null;
        }

        if ($font['family'] === ($this->defaults()['fonts'][$slot] ?? null)) {
            return null;
        }

        return sprintf('https://fonts.bunny.net/css?family=%s:%s', $font['bunny'], implode(',', $font['weights']));
    }

    public function fallbackFor(string $slot, string $fontName): string
    {
        return $this->fontDefinition($slot, $fontName)['fallback'] ?? 'sans-serif';
    }

    /**
     * @return array{colors: array<string, string>, fonts: array<string, string>}
     */
    public function defaults(): array
    {
        /** @var array{colors: array<string, string>, fonts: array<string, string>} $defaults */
        $defaults = $this->repository->defaults()['theme'] ?? ['colors' => [], 'fonts' => []];

        return $defaults;
    }

    /**
     * @return array{colors: array<string, string>, fonts: array<string, string>}
     */
    private function effectiveTheme(): array
    {
        $stored = Arr::get($this->repository->forRequest(), 'theme', []);

        return [
            'colors' => array_merge($this->defaults()['colors'], is_array($stored) ? (Arr::get($stored, 'colors', []) ?: []) : []),
            'fonts' => array_merge($this->defaults()['fonts'], is_array($stored) ? (Arr::get($stored, 'fonts', []) ?: []) : []),
        ];
    }

    /**
     * @return array<string, array{family: string, fallback: string, bunny: string, weights: list<int>}>
     */
    private function catalogue(string $slot): array
    {
        /** @var array<string, array{family: string, fallback: string, bunny: string, weights: list<int>}> $catalogue */
        $catalogue = config("gadya-cms.fonts.{$slot}", []);

        return $catalogue;
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $slot): array
    {
        return array_combine(
            array_keys($this->catalogue($slot)),
            array_keys($this->catalogue($slot)),
        );
    }

    /**
     * @return array{family: string, fallback: string, bunny: string, weights: list<int>}|null
     */
    private function fontDefinition(string $slot, string $fontName): ?array
    {
        return $this->catalogue($slot)[$fontName] ?? null;
    }
}
