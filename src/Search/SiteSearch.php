<?php

namespace Gadya\Cms\Search;

use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The site's own search box.
 *
 * Pages live in one document, so they are searched in PHP - a site with
 * a hundred pages is a hundred small arrays, and a query nobody can
 * index is still faster than a round trip. Articles are rows, so they
 * are searched in the database. Both come back as the same shape.
 */
class SiteSearch
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $public,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return Collection<int, array{title: string, url: string, kind: string, snippet: string, score: int}>
     */
    public function for(string $query, int $limit = 20): Collection
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return new Collection;
        }

        return $this->pages($terms)
            ->concat($this->articles($terms))
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * @param  list<string>  $terms
     * @return Collection<int, array{title: string, url: string, kind: string, snippet: string, score: int}>
     */
    private function pages(array $terms): Collection
    {
        $document = $this->public->from($this->repository->published());
        $results = new Collection;

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || $this->registry->isHidden($page) || ! empty($page['seo']['noindex'])) {
                continue;
            }

            $title = (string) ($page['title'] ?? $slug);
            $heading = (string) ($page['heading'] ?? '');
            $body = $this->textOf($page);
            $score = $this->score($terms, $title.' '.$heading, $body);

            if ($score > 0) {
                $results->push([
                    'title' => $heading !== '' ? $heading : $title,
                    'url' => url($this->registry->publicPathFor((string) $slug, $document)),
                    'kind' => 'Page',
                    'snippet' => $this->snippet($terms, trim(($page['description'] ?? '').' '.$body)),
                    'score' => $score,
                ]);
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $terms
     * @return Collection<int, array{title: string, url: string, kind: string, snippet: string, score: int}>
     */
    private function articles(array $terms): Collection
    {
        $query = $this->blog->liveQuery();

        foreach ($terms as $term) {
            $query->where(function ($builder) use ($term): void {
                foreach (['title', 'excerpt', 'content'] as $column) {
                    $builder->orWhere($column, 'like', '%'.$term.'%');
                }
            });
        }

        return $query->limit(50)->get()->map(function (Post $post) use ($terms): array {
            $body = trim(strip_tags((string) $post->content));

            return [
                'title' => $post->title,
                'url' => url($post->publicPath()),
                'kind' => 'Article',
                'snippet' => $this->snippet($terms, trim(($post->excerpt ?? '').' '.$body)),
                'score' => $this->score($terms, $post->title, $body.' '.$post->excerpt),
            ];
        });
    }

    /**
     * A word in a title counts for more than the same word buried in the
     * body, which is the whole of the ranking and is enough at this size.
     *
     * @param  list<string>  $terms
     */
    private function score(array $terms, string $title, string $body): int
    {
        $title = Str::lower($title);
        $body = Str::lower($body);
        $score = 0;

        foreach ($terms as $term) {
            $score += substr_count($title, $term) * 10;
            $score += min(substr_count($body, $term), 5);
        }

        return $score;
    }

    /**
     * @param  list<string>  $terms
     */
    private function snippet(array $terms, string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        if ($text === '') {
            return '';
        }

        $at = false;

        foreach ($terms as $term) {
            $at = mb_stripos($text, $term);

            if ($at !== false) {
                break;
            }
        }

        $start = max(0, ($at === false ? 0 : $at) - 60);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($text, $start), 180);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function textOf(array $page): string
    {
        $text = [];

        array_walk_recursive($page, function ($value, $key) use (&$text): void {
            if (is_string($value) && ! in_array($key, ['type', 'status', 'slug', 'hero_image', 'image', 'og_image'], true) && ! str_ends_with($value, '.webp')) {
                $text[] = $value;
            }
        });

        return implode(' ', $text);
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower(trim($query))) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }
}
