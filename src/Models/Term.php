<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A category or a tag. Categories are the few shelves an article sits on;
 * tags are the many words it shares with others. Both give a visitor - and
 * a search engine - another way in.
 */
class Term extends Model
{
    public const CATEGORY = 'category';

    public const TAG = 'tag';

    protected $table = 'gadyacms_terms';

    /** @var list<string> */
    protected $fillable = ['site_id', 'taxonomy', 'name', 'slug', 'description', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'taxonomy' => self::CATEGORY,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /**
     * A term named but not given an address gets one from its name, and
     * never collides with another in the same taxonomy.
     */
    protected static function booted(): void
    {
        static::saving(function (self $term): void {
            $term->site_id ??= app(SiteContext::class)->id();
            $term->slug = Str::slug($term->slug ?: $term->name);

            $taken = static::query()
                ->where('site_id', $term->site_id)
                ->where('taxonomy', $term->taxonomy)
                ->where('slug', $term->slug)
                ->whereKeyNot($term->getKey())
                ->exists();

            if ($taken) {
                $term->slug .= '-'.(static::query()->where('site_id', $term->site_id)->where('taxonomy', $term->taxonomy)->count() + 1);
            }
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsToMany<Post, $this> */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'gadyacms_post_term');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCategories(Builder $query): Builder
    {
        return $query->where('taxonomy', self::CATEGORY);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeTags(Builder $query): Builder
    {
        return $query->where('taxonomy', self::TAG);
    }

    public function isCategory(): bool
    {
        return $this->taxonomy === self::CATEGORY;
    }

    public function publicPath(): string
    {
        $prefix = trim((string) config('gadya-cms.blog.prefix', 'blog'), '/');
        $segment = trim((string) config('gadya-cms.blog.'.($this->isCategory() ? 'category_prefix' : 'tag_prefix'), $this->isCategory() ? 'category' : 'tag'), '/');

        return "/{$prefix}/{$segment}/{$this->slug}";
    }

    /**
     * @return array<string, string>
     */
    public static function taxonomyLabels(): array
    {
        return [self::CATEGORY => 'Category', self::TAG => 'Tag'];
    }
}
