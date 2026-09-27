<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Localisation\Locales;

/**
 * The site as an AI assistant would like to read it: one short file
 * saying what the site is, then every page and article with a line each,
 * following the llms.txt convention.
 */
class LlmsText
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    public function render(): string
    {
        $document = $this->repository->published();
        $name = (string) config('gadya-cms.seo.site_name', config('gadya-cms.brand.name', config('app.name')));
        $description = trim((string) config('gadya-cms.seo.default_description', ''));

        $lines = ["# {$name}"];

        if ($description !== '') {
            $lines[] = '';
            $lines[] = "> {$description}";
        }

        $organisation = (array) config('gadya-cms.seo.organization', []);
        $facts = array_filter([
            filled($organisation['telephone'] ?? null) ? 'Telephone: '.$organisation['telephone'] : null,
            filled($document['address'] ?? null) ? 'Address: '.$document['address'] : null,
            filled($organisation['area'] ?? null) ? 'Serves: '.$organisation['area'] : null,
        ]);

        if ($facts !== []) {
            $lines[] = '';
            array_push($lines, ...array_map(fn (string $fact): string => "- {$fact}", $facts));
        }

        $lines[] = '';
        $lines[] = '## Pages';

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || $this->registry->isHidden($page) || ! empty($page['seo']['noindex'])) {
                continue;
            }

            $summary = trim(strip_tags((string) ($page['seo']['meta_description'] ?? $page['description'] ?? '')));
            $lines[] = '- ['.($page['title'] ?? $slug).']('.url($this->registry->publicPathFor((string) $slug, $document)).')'.($summary !== '' ? ': '.$summary : '');
        }

        if (GadyaCmsPlugin::get()->hasBlog() && config('gadya-cms.blog.routes', true)) {
            $posts = $this->blog->liveQuery()->latest('published_at')->limit(50)->get();

            if ($posts->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '## Articles';

                foreach ($posts as $post) {
                    $lines[] = '- ['.$post->title.']('.url($post->publicPath()).')'.(filled($post->excerpt) ? ': '.trim((string) $post->excerpt) : '');
                }
            }
        }

        /*
         * The list above is the default language. A reader who wants
         * another finds the same pages under its prefix.
         */
        $locales = app(Locales::class);

        if ($locales->isMultilingual()) {
            $lines[] = '';
            $lines[] = '## Languages';

            foreach ($locales->enabled() as $locale) {
                $lines[] = '- '.$locales->name($locale).' ('.$locale.'): '.$locales->url('/', $locale).($locale === $locales->default() ? ' - the addresses above' : ' - the same addresses under '.$locales->prefix($locale).'/');
            }
        }

        if (config('gadya-cms.seo.markdown', true)) {
            $lines[] = '';
            $lines[] = '## Optional';
            $lines[] = '- Every page and article above can be requested with `Accept: text/markdown` for a plain-text version.';
        }

        return implode("\n", $lines)."\n";
    }
}
