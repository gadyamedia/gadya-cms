<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;

/**
 * How ready the site is to be read by machines - search engines and the
 * AI assistants that now answer questions from the web. Every check is
 * something the package can fix or the client can, with the fix named.
 *
 * Judged from what the site would serve, not by fetching it, so it works
 * on a laptop and costs nothing; `gadya-cms:agent-ready --live` adds the
 * checks that only a real request can answer.
 */
class AgentReadiness
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return array{score: int, passed: int, total: int, checks: list<array{label: string, group: string, points: int, passed: bool, fix: string}>}
     */
    public function audit(): array
    {
        $document = $this->repository->published();
        $pages = array_filter((array) ($document['pages'] ?? []), fn ($page): bool => is_array($page) && ! $this->registry->isHidden($page));
        $pageCount = max(1, count($pages));

        $withDescription = count(array_filter($pages, fn (array $page): bool => trim((string) ($page['seo']['meta_description'] ?? $page['description'] ?? '')) !== ''));
        $withSnippet = count(array_filter($pages, fn (array $page): bool => trim((string) ($page['seo']['meta_title'] ?? '')) !== '' && trim((string) ($page['seo']['meta_description'] ?? '')) !== ''));
        $organisation = (array) config('gadya-cms.seo.organization', []);
        $articles = GadyaCmsPlugin::get()->hasBlog() ? $this->blog->liveQuery()->count() : 0;
        $withFaq = GadyaCmsPlugin::get()->hasBlog() ? $this->blog->liveQuery()->whereNotNull('faq')->where('faq', '!=', '[]')->count() : 0;

        $checks = [
            ['group' => 'Discoverability', 'label' => 'robots.txt is served', 'points' => 10, 'passed' => (bool) config('gadya-cms.seo.robots', true), 'fix' => 'Turn on seo.robots (or keep your own public/robots.txt).'],
            ['group' => 'Discoverability', 'label' => 'sitemap.xml is served and linked from robots.txt', 'points' => 10, 'passed' => (bool) config('gadya-cms.seo.sitemap', true) && (bool) config('gadya-cms.seo.robots', true), 'fix' => 'Turn on seo.sitemap.'],
            ['group' => 'Discoverability', 'label' => 'llms.txt describes the site for AI assistants', 'points' => 15, 'passed' => (bool) config('gadya-cms.seo.llms', true), 'fix' => 'Turn on seo.llms.'],
            ['group' => 'Bot access', 'label' => 'AI crawlers are told where they may go', 'points' => 10, 'passed' => (array) config('gadya-cms.seo.ai_crawlers.allow', []) !== [] || (array) config('gadya-cms.seo.ai_crawlers.block', []) !== [], 'fix' => 'List the AI crawlers to allow or block under seo.ai_crawlers.'],
            ['group' => 'Bot access', 'label' => 'robots.txt says how AI may use the content (Content Signals)', 'points' => 5, 'passed' => (bool) config('gadya-cms.seo.robots', true) && array_filter((array) config('gadya-cms.seo.content_signals', [])) !== [], 'fix' => 'Set seo.content_signals, e.g. search=yes, ai-input=yes, ai-train=no.'],
            ['group' => 'Content', 'label' => 'Articles can be read as Markdown (Accept: text/markdown)', 'points' => 10, 'passed' => (bool) config('gadya-cms.seo.markdown', true), 'fix' => 'Turn on seo.markdown.'],
            ['group' => 'Content', 'label' => 'Every visible page has a description', 'points' => 10, 'passed' => $withDescription === count($pages), 'fix' => 'Write a description (or a search snippet) for every page: '.(count($pages) - $withDescription).' missing.'],
            ['group' => 'Content', 'label' => 'Most pages have a written search snippet', 'points' => 5, 'passed' => $withSnippet / $pageCount >= 0.5, 'fix' => 'Write a title and description under In search results for at least half the pages ('.$withSnippet.' of '.count($pages).').'],
            ['group' => 'Structured data', 'label' => 'The organisation is described in JSON-LD', 'points' => 10, 'passed' => filled($organisation['telephone'] ?? null) || filled($organisation['same_as'] ?? null) || filled(config('gadya-cms.seo.site_name')), 'fix' => 'Set seo.site_name and seo.organization (telephone, same_as).'],
            ['group' => 'Structured data', 'label' => 'Articles carry Article and FAQ structured data', 'points' => 10, 'passed' => $articles === 0 || $withFaq > 0, 'fix' => 'Add questions and answers to your articles; each becomes FAQPage data.'],
            ['group' => 'Structured data', 'label' => 'Every page has a canonical address and Open Graph tags', 'points' => 5, 'passed' => true, 'fix' => 'Rendered by @cmsSeo.'],
            ['group' => 'Content', 'label' => 'There is something to read beyond the pages', 'points' => 5, 'passed' => $articles > 0 || ! GadyaCmsPlugin::get()->hasBlog(), 'fix' => 'Publish an article; assistants cite pages that answer questions.'],
        ];

        $total = array_sum(array_column($checks, 'points'));
        $earned = array_sum(array_map(fn (array $check): int => $check['passed'] ? $check['points'] : 0, $checks));

        return [
            'score' => $total > 0 ? (int) round($earned / $total * 100) : 0,
            'passed' => count(array_filter($checks, fn (array $check): bool => $check['passed'])),
            'total' => count($checks),
            'checks' => $checks,
        ];
    }
}
