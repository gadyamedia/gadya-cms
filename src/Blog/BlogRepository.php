<?php

namespace Gadya\Cms\Blog;

use Gadya\Cms\Models\Post;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
