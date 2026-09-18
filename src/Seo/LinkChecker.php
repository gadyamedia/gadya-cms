<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\BrokenLink;
use Gadya\Cms\Redirects\RedirectMap;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Walks everything published looking for links that lead nowhere.
 *
 * Internal links are checked against the site's own addresses, which
 * costs nothing and catches the common case: a page renamed, a link left
 * behind. External links are only checked when asked for, because that
 * means a request per link and somebody else's server deciding how long
 * to take about it.
 */
class LinkChecker
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return array{checked: int, broken: int}
     */
    public function check(bool $external = false): array
    {
        $document = $this->repository->published();
        $known = $this->knownPaths($document);
        $checked = 0;
        $broken = 0;

        foreach ($this->sources($document) as $source) {
            foreach ($this->linksIn($source['html']) as $link) {
                $checked++;

                $isExternal = (bool) preg_match('~^https?://~i', $link);

                if ($isExternal && ! $external) {
                    continue;
                }

                $failure = $isExternal ? $this->checkExternal($link) : $this->checkInternal($link, $known);

                if ($failure !== null) {
                    BrokenLink::note($link, BrokenLink::LINKED, $source['label'], null, $failure);
                    $broken++;
                }
            }
        }

        return ['checked' => $checked, 'broken' => $broken];
    }

    /**
     * Every address this site answers on, as paths.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function knownPaths(array $document): array
    {
        $paths = ['/'];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (is_array($page) && ! $this->registry->isHidden($page)) {
                $paths[] = $this->registry->publicPathFor((string) $slug, $document);
            }
        }

        foreach ($this->blog->liveQuery()->pluck('slug') as $slug) {
            $paths[] = '/'.trim((string) config('gadya-cms.blog.prefix', 'blog'), '/').'/'.$slug;
        }

        return array_values(array_unique($paths));
    }

    /**
     * Where the site's own words live: the published pages and articles.
     *
     * @param  array<string, mixed>  $document
     * @return list<array{label: string, html: string}>
     */
    private function sources(array $document): array
    {
        $sources = [];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || $this->registry->isHidden($page)) {
                continue;
            }

            $text = [];
            array_walk_recursive($page, function ($value) use (&$text): void {
                if (is_string($value) && str_contains($value, 'href=')) {
                    $text[] = $value;
                }
            });

            if ($text !== []) {
                $sources[] = ['label' => 'Page: '.($page['title'] ?? $slug), 'html' => implode(' ', $text)];
            }
        }

        foreach ($this->blog->liveQuery()->get(['title', 'content']) as $post) {
            $sources[] = ['label' => 'Article: '.$post->title, 'html' => (string) $post->content];
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function linksIn(string $html): array
    {
        preg_match_all('~<a\s[^>]*href=(["\'])(.*?)\1~i', $html, $matches);

        return collect($matches[2] ?? [])
            ->map(fn (string $href): string => trim(html_entity_decode($href)))
            ->reject(fn (string $href): bool => $href === ''
                || Str::startsWith($href, ['#', 'mailto:', 'tel:', 'javascript:', 'data:'])
                || Str::startsWith($href, '//'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $known
     */
    private function checkInternal(string $link, array $known): ?int
    {
        $path = '/'.trim((string) parse_url($link, PHP_URL_PATH), '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        if (in_array($path, $known, true)) {
            return null;
        }

        /*
         * An address the site forwards is not broken - that is what the
         * redirect is for.
         */
        if (app(RedirectMap::class)->match($path) !== null) {
            return null;
        }

        return 404;
    }

    private function checkExternal(string $link): ?int
    {
        return rescue(function () use ($link): ?int {
            $response = Http::timeout(10)->withHeaders(['User-Agent' => 'GadyaCMS link checker'])->head($link);

            return $response->status() >= 400 ? $response->status() : null;
        }, 0, report: false);
    }
}
