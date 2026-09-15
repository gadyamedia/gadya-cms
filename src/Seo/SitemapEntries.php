<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Illuminate\Support\Carbon;

/**
 * Every public address worth telling a search engine about: the visible
 * pages, the live articles, and anything the application adds.
 */
class SitemapEntries
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return list<array{loc: string, lastmod: string|null, priority: string}>
     */
    public function all(): array
    {
        $document = $this->repository->published();
        $entries = [];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || $this->registry->isHidden($page) || ! empty($page['seo']['noindex'])) {
                continue;
            }

            $path = $this->registry->publicPathFor((string) $slug, $document);

            $entries[] = [
                'loc' => url($path),
                'lastmod' => null,
                'priority' => $path === '/' ? '1.0' : '0.7',
            ];
        }

        if (GadyaCmsPlugin::get()->hasBlog() && config('gadya-cms.blog.routes', true)) {
            $posts = $this->blog->liveQuery()->latest('published_at')->get();

            if ($posts->isNotEmpty()) {
                $entries[] = [
                    'loc' => url('/'.trim((string) config('gadya-cms.blog.prefix', 'blog'), '/')),
                    'lastmod' => $posts->first()->updated_at?->toAtomString(),
                    'priority' => '0.6',
                ];
            }

            foreach ($posts as $post) {
                $entries[] = [
                    'loc' => url($post->publicPath()),
                    'lastmod' => ($post->updated_at ?? $post->published_at)?->toAtomString(),
                    'priority' => '0.5',
                ];
            }
        }

        foreach ((array) config('gadya-cms.seo.sitemap_extra', []) as $path) {
            $entries[] = ['loc' => url((string) $path), 'lastmod' => Carbon::now()->toAtomString(), 'priority' => '0.5'];
        }

        return $entries;
    }
}
