<?php

namespace Gadya\Cms\Content;

/**
 * What the panel and its sign-in screen are painted with.
 *
 * By default this follows the site itself: the colours and type the client
 * chose under Look & feel, and the logo she picked there, so signing in
 * looks like her own website without anyone editing a config file. A site
 * that wants the panel to look different sets `brand.follow_site` to false
 * and fills in `brand.*` by hand.
 */
class PanelBrand
{
    /** Panel colour => the site colour it follows, when the site has one. */
    private const FOLLOWS = [
        'primary' => 'primary',
        'secondary' => 'secondary',
        'background' => 'background',
        'ink' => 'ink',
        'accent' => 'accent',
    ];

    public function __construct(
        private readonly SiteTheme $theme,
        private readonly SiteContentRepository $repository,
    ) {}

    public function followsSite(): bool
    {
        return (bool) config('gadya-cms.brand.follow_site', true);
    }

    /**
     * @return array<string, string|null>
     */
    public function tokens(): array
    {
        $colors = $this->followsSite() ? $this->siteColors() : [];
        $font = $this->displayFont();

        return [
            'primary' => $colors['primary'] ?? (string) config('gadya-cms.brand.primary', '#9f12c7'),
            'secondary' => $colors['secondary'] ?? (string) config('gadya-cms.brand.secondary', '#f54fa3'),
            'background' => $colors['background'] ?? (string) config('gadya-cms.brand.background', '#fffdf3'),
            'ink' => $colors['ink'] ?? (string) config('gadya-cms.brand.ink', '#000000'),
            'accent' => $colors['accent'] ?? (string) config('gadya-cms.brand.accent', '#f0e56c'),
            'displayFont' => $font,
            'logoHeight' => (string) config('gadya-cms.brand.logo_height', '2.75rem'),
            'logoHeightAuth' => (string) config('gadya-cms.brand.logo_height_auth', '5rem'),
            'fontStylesheet' => $this->fontStylesheet($font),
        ];
    }

    /**
     * The logo, as a filename in the photo library or a path under public/.
     * The one the client picked wins; the site's own config is the fallback.
     */
    public function logo(): ?string
    {
        $chosen = $this->followsSite() ? $this->repository->forRequest()['logo'] ?? null : null;
        $logo = is_string($chosen) && $chosen !== '' ? $chosen : config('gadya-cms.brand.logo');

        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    /**
     * The site's own colours, keyed as the panel names them. A site whose
     * palette has different names simply keeps the configured ones.
     *
     * @return array<string, string>
     */
    private function siteColors(): array
    {
        $colors = rescue(fn (): array => $this->theme->colors(), [], report: false);
        $followed = [];

        foreach (self::FOLLOWS as $token => $siteColor) {
            if (isset($colors[$siteColor]) && is_string($colors[$siteColor])) {
                $followed[$token] = $colors[$siteColor];
            }
        }

        return $followed;
    }

    private function displayFont(): string
    {
        if (! $this->followsSite()) {
            return (string) config('gadya-cms.brand.fonts.display', 'Georgia');
        }

        return (string) (rescue(fn (): array => $this->theme->fonts(), [], report: false)['display']
            ?? config('gadya-cms.brand.fonts.display', 'Georgia'));
    }

    private function fontStylesheet(string $font): ?string
    {
        if (! $this->followsSite()) {
            return config('gadya-cms.brand.fonts.stylesheet');
        }

        return rescue(fn (): ?string => $this->theme->bunnyLinkFor('display', $font), null, report: false)
            ?? config('gadya-cms.brand.fonts.stylesheet');
    }
}
