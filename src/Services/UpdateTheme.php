<?php

namespace Gadya\Cms\Services;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteTheme;
use RuntimeException;

class UpdateTheme
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly SiteTheme $theme,
    ) {}

    /**
     * @param  array<string, string>  $colors
     * @param  array<string, string>  $fonts
     */
    public function handle(array $colors, array $fonts, ?string $logo = null): void
    {
        $allowedColorKeys = $this->theme->allowedColorKeys();

        foreach ($allowedColorKeys as $key) {
            if (! array_key_exists($key, $colors)) {
                throw new RuntimeException("The colour [{$key}] is required.");
            }

            if (! preg_match(SiteTheme::COLOR_PATTERN, (string) $colors[$key])) {
                throw new RuntimeException("The colour [{$key}] must be a 6-digit hex value.");
            }
        }

        $displayFonts = array_keys($this->theme->curatedDisplayFonts());
        $sansFonts = array_keys($this->theme->curatedSansFonts());

        if (! in_array($fonts['display'] ?? null, $displayFonts, true)) {
            throw new RuntimeException('The display font is not on the curated list.');
        }

        if (! in_array($fonts['sans'] ?? null, $sansFonts, true)) {
            throw new RuntimeException('The body font is not on the curated list.');
        }

        $document = $this->repository->draft();

        $document['theme'] = [
            'colors' => array_intersect_key($colors, array_flip($allowedColorKeys)),
            'fonts' => [
                'display' => $fonts['display'],
                'sans' => $fonts['sans'],
            ],
        ];

        if ($logo !== null && $logo !== '') {
            $document['logo'] = $logo;
        } else {
            unset($document['logo']);
        }

        $this->repository->saveDraft($document);
    }

    public function reset(): void
    {
        $document = $this->repository->draft();
        $document['theme'] = $this->theme->defaults();

        $this->repository->saveDraft($document);
    }
}
