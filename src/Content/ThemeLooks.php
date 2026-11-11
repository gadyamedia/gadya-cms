<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Options\Options;
use Gadya\Cms\Services\UpdateTheme;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Whole looks - colours and type together - the client switches between in one go: a Christmas look in
 * December, back to the everyday one in January. Ready-made looks come from the site (`theme_looks` in
 * config, written for its own colours); the client saves her own. Switching puts the look in the draft,
 * and the first switch keeps the look she had as "Everyday", so going back is one click too.
 */
class ThemeLooks
{
    public const OPTION_KEY = 'theme.looks';

    public const EVERYDAY = 'everyday';

    public function __construct(
        private readonly SiteTheme $theme,
        private readonly SiteContentRepository $repository,
        private readonly Options $options,
    ) {}

    /**
     * Every look on offer: her saved ones first (Everyday leading), then the site's ready-made ones.
     *
     * @return array<string, array{label: string, colors: array<string, string>, fonts: array<string, string>, saved: bool}>
     */
    public function all(): array
    {
        $saved = array_map(fn (array $look): array => [...$look, 'saved' => true], $this->saved());
        uksort($saved, fn (string $a, string $b): int => ($b === self::EVERYDAY) <=> ($a === self::EVERYDAY));

        $readyMade = [];

        foreach ((array) config('gadya-cms.theme_looks', []) as $key => $look) {
            if (is_array($look) && ! isset($saved[$key])) {
                $readyMade[(string) $key] = [
                    'label' => (string) ($look['label'] ?? Str::headline((string) $key)),
                    'colors' => (array) ($look['colors'] ?? []),
                    'fonts' => (array) ($look['fonts'] ?? []),
                    'saved' => false,
                ];
            }
        }

        return [...$saved, ...$readyMade];
    }

    /**
     * Put a look in the draft. A look may name only some colours; the rest keep the original design's.
     */
    public function apply(string $key): void
    {
        $look = $this->all()[$key] ?? throw new RuntimeException('That look is not there any more.');

        if ($key !== self::EVERYDAY && ! isset($this->saved()[self::EVERYDAY])) {
            $current = $this->current();
            $this->save('Everyday', $current['colors'], $current['fonts'], self::EVERYDAY);
        }

        $defaults = $this->theme->defaults();

        app(UpdateTheme::class)->handle(
            [...$defaults['colors'], ...$look['colors']],
            [...$defaults['fonts'], ...$look['fonts']],
            $this->repository->draft()['logo'] ?? null,
        );
    }

    /**
     * Keep a look under a name; the same name again replaces it.
     *
     * @param  array<string, string>  $colors
     * @param  array<string, string>  $fonts
     */
    public function save(string $label, array $colors, array $fonts, ?string $key = null): string
    {
        $label = trim($label);
        $key ??= Str::slug($label);

        if ($label === '' || $key === '') {
            throw new RuntimeException('Give the look a name.');
        }

        $looks = $this->saved();
        $looks[$key] = [
            'label' => $label,
            'colors' => array_intersect_key($colors, array_flip($this->theme->allowedColorKeys())),
            'fonts' => array_intersect_key($fonts, array_flip(['display', 'sans'])),
        ];

        $this->options->set(self::OPTION_KEY, $looks);

        return $key;
    }

    public function delete(string $key): void
    {
        $looks = $this->saved();
        unset($looks[$key]);

        $this->options->set(self::OPTION_KEY, $looks);
    }

    /**
     * The look in the draft right now.
     *
     * @return array{colors: array<string, string>, fonts: array<string, string>}
     */
    public function current(): array
    {
        $defaults = $this->theme->defaults();
        $theme = $this->repository->draft()['theme'] ?? [];

        return [
            'colors' => [...$defaults['colors'], ...(array) ($theme['colors'] ?? [])],
            'fonts' => [...$defaults['fonts'], ...(array) ($theme['fonts'] ?? [])],
        ];
    }

    /**
     * @return array<string, array{label: string, colors: array<string, string>, fonts: array<string, string>}>
     */
    private function saved(): array
    {
        $looks = $this->options->get(self::OPTION_KEY, []);

        return is_array($looks) ? array_filter($looks, 'is_array') : [];
    }
}
