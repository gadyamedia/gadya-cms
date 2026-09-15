<?php

namespace Gadya\Cms\Blog;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Illuminate\Support\Str;

/**
 * The pages on this site an article about a topic should link to,
 * scored by how many of the topic's words appear in each page's title
 * and description. Crude, and good enough: the model is told these are
 * the only paths that exist, so the worst case is a sensible link the
 * client did not need, never one that 404s.
 */
class InternalLinkSuggester
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PageRegistry $registry,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return list<array{label: string, url: string}>
     */
    public function suggest(string $topic, string $keyword = '', int $limit = 6): array
    {
        $document = $this->repository->published();
        $candidates = [];

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || ($page['status'] ?? null) === PageRegistry::STATUS_ARCHIVED) {
                continue;
            }

            $candidates[] = [
                'label' => (string) ($page['title'] ?? $slug),
                'url' => $this->registry->publicPathFor((string) $slug, $document),
                'haystack' => Str::lower(implode(' ', array_filter([
                    $page['title'] ?? '', $page['heading'] ?? '', $page['description'] ?? '',
                ], 'is_string'))),
            ];
        }

        foreach ($this->blog->liveQuery()->latest('published_at')->limit(30)->get() as $post) {
            $candidates[] = [
                'label' => $post->title,
                'url' => $post->publicPath(),
                'haystack' => Str::lower($post->title.' '.$post->excerpt),
            ];
        }

        $terms = Str::of($keyword.' '.$topic)
            ->lower()
            ->split('/[^a-z0-9]+/')
            ->filter(fn (string $term): bool => strlen($term) >= 4)
            ->unique();

        return collect($candidates)
            ->map(function (array $candidate) use ($terms): array {
                $candidate['score'] = $terms->filter(fn (string $term): bool => str_contains($candidate['haystack'], $term))->count();

                return $candidate;
            })
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $candidate): array => ['label' => $candidate['label'], 'url' => $candidate['url']])
            ->values()
            ->all();
    }
}
