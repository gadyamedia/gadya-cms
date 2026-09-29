<?php

namespace Gadya\Cms\Localisation;

use Closure;

/**
 * Which languages the site speaks, which one this request is in, and the
 * address of any page in any of them.
 *
 * The default language lives at the root (`/about`); every other one under
 * its own prefix (`/es/about`). With one language enabled - the default -
 * every answer here is the one the site always gave, so nothing else has
 * to ask whether multi-language is on.
 */
class Locales
{
    private ?string $current = null;

    public function default(): string
    {
        return (string) config('gadya-cms.locales.default', 'en');
    }

    /**
     * The enabled languages, default first.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $enabled = array_values(array_filter(
            array_map(fn ($code): string => strtolower(trim((string) $code)), (array) config('gadya-cms.locales.enabled', [])),
            fn (string $code): bool => preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code) === 1,
        ));

        return array_values(array_unique([$this->default(), ...$enabled]));
    }

    /**
     * The languages a page can be translated into.
     *
     * @return list<string>
     */
    public function additional(): array
    {
        return array_values(array_diff($this->enabled(), [$this->default()]));
    }

    public function isMultilingual(): bool
    {
        return $this->additional() !== [];
    }

    public function isEnabled(?string $locale): bool
    {
        return $locale !== null && in_array($locale, $this->enabled(), true);
    }

    /** The language this request is being answered in. */
    public function current(): string
    {
        return $this->current ?? $this->default();
    }

    public function use(?string $locale): void
    {
        $this->current = $this->isEnabled($locale) ? $locale : null;
    }

    /**
     * Run something in the default language, whatever this request is in:
     * reading the original of a piece that is about to be translated.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function inDefault(Closure $callback): mixed
    {
        $current = $this->current;
        $this->current = null;

        try {
            return $callback();
        } finally {
            $this->current = $current;
        }
    }

    /** Whether this request is in a language other than the default. */
    public function isTranslating(): bool
    {
        return $this->current() !== $this->default();
    }

    public function name(string $locale): string
    {
        $names = (array) config('gadya-cms.locales.names', []);

        return (string) ($names[$locale] ?? strtoupper($locale));
    }

    /** The name of a language in English, for the translator and the panel. */
    public function englishName(string $locale): string
    {
        return match (explode('-', $locale)[0]) {
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
            'ht' => 'Haitian Creole',
            'pl' => 'Polish',
            'ru' => 'Russian',
            'uk' => 'Ukrainian',
            'ar' => 'Arabic',
            'hi' => 'Hindi',
            'gu' => 'Gujarati',
            'tl' => 'Tagalog',
            'vi' => 'Vietnamese',
            default => $this->name($locale),
        };
    }

    /** The path prefix a language lives under: '' for the default, '/es' for Spanish. */
    public function prefix(string $locale): string
    {
        return $locale === $this->default() ? '' : '/'.$locale;
    }

    /**
     * A path on the site in another language. The path is given as the
     * default language has it - `/about`, or `/` for the home page.
     */
    public function path(string $path, string $locale): string
    {
        $path = '/'.ltrim($path, '/');
        $prefix = $this->prefix($locale);

        if ($prefix === '') {
            return $path;
        }

        return $path === '/' ? $prefix : $prefix.$path;
    }

    /** The full address of a path in a language, on the site's own root. */
    public function url(string $path, string $locale): string
    {
        $path = $this->path($path, $locale);

        /* The home page is the bare root, as url('/') writes it. */
        return rtrim($this->root(), '/').($path === '/' ? '' : $path);
    }

    /**
     * The same page in every enabled language, keyed by language - for the
     * switcher, the hreflang links and the sitemap.
     *
     * @return array<string, string>
     */
    public function alternates(string $path): array
    {
        $alternates = [];

        foreach ($this->enabled() as $locale) {
            $alternates[$locale] = $this->url($path, $locale);
        }

        return $alternates;
    }

    /**
     * Turn an address the site built in the default language into its path,
     * so it can be offered in another one. An address on another host is
     * not ours to translate and comes back as null.
     */
    public function pathOf(string $url): ?string
    {
        $root = rtrim($this->root(), '/');

        if (str_starts_with($url, '/')) {
            return $url;
        }

        if ($url === $root) {
            return '/';
        }

        return str_starts_with($url, $root.'/') ? substr($url, strlen($root)) : null;
    }

    /**
     * The site root in the default language. While a request is being
     * answered in another language, url('/') already carries its prefix,
     * so the root is rebuilt from the request's own host and base.
     */
    public function root(): string
    {
        $request = app('request');
        $base = rtrim($request->getSchemeAndHttpHost().$request->getBaseUrl(), '/');
        $prefix = $this->prefix($this->current());

        if ($prefix !== '' && str_ends_with($base, $prefix)) {
            return substr($base, 0, -strlen($prefix));
        }

        return app('url')->to('/');
    }

    /**
     * What the portal is told about the site's languages.
     *
     * @return array{default: string, enabled: list<string>}
     */
    public function summary(): array
    {
        return ['default' => $this->default(), 'enabled' => $this->enabled()];
    }
}
