<?php

namespace Gadya\Cms\Seo;

use Closure;
use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Events\EventCalendar;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Illuminate\Support\Carbon;

/**
 * Every public address worth telling a search engine about: the visible
 * pages, the live articles, and anything the application adds.
 *
 * A site with its own records (rentals, services, job postings) adds
 * their addresses from a service provider's boot():
 *
 *     SitemapEntries::add(fn () => Rental::query()->pluck('slug')->map(fn ($slug) => "/rentals/{$slug}"));
 *
 * The callback returns paths, or arrays with `loc` and optionally
 * `lastmod` and `priority`, and runs each time the sitemap is built.
 */
class SitemapEntries
{
    /** @var list<Closure(): iterable<string|array{loc: string, lastmod?: string|null, priority?: string}>> */
    private static array $extenders = [];

    /**
     * @param  Closure(): iterable<string|array{loc: string, lastmod?: string|null, priority?: string}>  $entries
     */
    public static function add(Closure $entries): void
    {
        self::$extenders[] = $entries;
    }

    public static function flushExtenders(): void
    {
        self::$extenders = [];
    }

    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
        private readonly EventCalendar $events,
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

        if (GadyaCmsPlugin::get()->hasEvents() && config('gadya-cms.events.routes', true)) {
            $events = $this->events->upcoming(200);

            if ($events->isNotEmpty()) {
                $entries[] = [
                    'loc' => url('/'.trim((string) config('gadya-cms.events.prefix', 'events'), '/')),
                    'lastmod' => $events->first()->updated_at?->toAtomString(),
                    'priority' => '0.6',
                ];
            }

            foreach ($events as $event) {
                $entries[] = [
                    'loc' => url($event->publicPath()),
                    'lastmod' => $event->updated_at?->toAtomString(),
                    'priority' => '0.5',
                ];
            }
        }

        foreach ((array) config('gadya-cms.seo.sitemap_extra', []) as $path) {
            $entries[] = ['loc' => url((string) $path), 'lastmod' => Carbon::now()->toAtomString(), 'priority' => '0.5'];
        }

        foreach (self::$extenders as $extender) {
            foreach ($extender() as $entry) {
                $entry = is_array($entry) ? $entry : ['loc' => (string) $entry];

                $entries[] = [
                    'loc' => url((string) $entry['loc']),
                    'lastmod' => $entry['lastmod'] ?? null,
                    'priority' => (string) ($entry['priority'] ?? '0.5'),
                ];
            }
        }

        return $entries;
    }
}
