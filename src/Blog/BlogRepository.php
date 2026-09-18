<?php

namespace Gadya\Cms\Blog;

use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The articles a visitor may read, for whatever templates the application
 * renders them with.
 */
class BlogRepository
{
    public function __construct(private readonly SiteContext $siteContext) {}

    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public function live(?int $perPage = null): LengthAwarePaginator
    {
        return $this->liveQuery()
            ->latest('published_at')
            ->paginate($perPage ?? (int) config('gadya-cms.blog.per_page', 12));
    }

    public function findLive(string $slug): ?Post
    {
        return $this->liveQuery()->where('slug', $slug)->first();
    }

    /**
     * @return Builder<Post>
     */
    public function liveQuery(): Builder
    {
        return Post::query()->live()->where('site_id', $this->siteContext->id());
    }

    /**
     * Something the client is working on, shown to her alone when the
     * editor or a preview link is on.
     */
    public function findAny(string $slug): ?Post
    {
        return Post::query()->where('site_id', $this->siteContext->id())->where('slug', $slug)->first();
    }

    /**
     * The live articles filed under a category or tag.
     *
     * @return LengthAwarePaginator<int, Post>
     */
    public function inTerm(Term $term, ?int $perPage = null): LengthAwarePaginator
    {
        return $this->liveQuery()
            ->whereHas('terms', fn (Builder $query) => $query->whereKey($term->getKey()))
            ->latest('published_at')
            ->paginate($perPage ?? (int) config('gadya-cms.blog.per_page', 12));
    }

    public function findTerm(string $taxonomy, string $slug): ?Term
    {
        return Term::query()
            ->where('site_id', $this->siteContext->id())
            ->where('taxonomy', $taxonomy)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Other articles a reader of this one would want: those sharing the most
     * categories and tags, then the most recent, never the article itself.
     *
     * @return Collection<int, Post>
     */
    public function related(Post $post, int $limit = 3): Collection
    {
        $termIds = $post->terms()->pluck('gadyacms_terms.id')->all();

        $query = $this->liveQuery()->whereKeyNot($post->getKey());

        if ($termIds !== []) {
            /*
             * Ordered by how much they share, then by date. The count comes
             * from a subquery rather than a HAVING clause, which SQLite
             * refuses on a query that is not grouped.
             */
            $shared = (clone $query)
                ->whereHas('terms', fn (Builder $terms) => $terms->whereIn('gadyacms_terms.id', $termIds))
                ->withCount(['terms as shared_terms' => fn (Builder $terms) => $terms->whereIn('gadyacms_terms.id', $termIds)])
                ->orderByDesc('shared_terms')
                ->latest('published_at')
                ->limit($limit)
                ->get();

            if ($shared->count() >= $limit) {
                return $shared;
            }

            return $shared->concat(
                $query->whereKeyNot($shared->modelKeys() ?: [0])->latest('published_at')->limit($limit - $shared->count())->get()
            );
        }

        return $query->latest('published_at')->limit($limit)->get();
    }

    /**
     * Terms with at least one live article, for a menu or a sidebar.
     *
     * @return Collection<int, Term>
     */
    public function termsInUse(string $taxonomy = Term::CATEGORY): Collection
    {
        return Term::query()
            ->where('site_id', $this->siteContext->id())
            ->where('taxonomy', $taxonomy)
            ->whereHas('posts', fn (Builder $posts) => $posts->live())
            ->withCount(['posts as live_posts_count' => fn (Builder $posts) => $posts->live()])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $candidate = $slug;
        $suffix = 2;

        while ($this->slugTaken($candidate, $ignoreId)) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Post::withTrashed()
            ->where('site_id', $this->siteContext->id())
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
