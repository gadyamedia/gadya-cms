<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    protected $table = 'gadyacms_posts';

    /** @var list<string> */
    protected $fillable = [
        'site_id', 'title', 'slug', 'excerpt', 'content', 'image', 'hero_alt',
        'meta_title', 'meta_description', 'faq', 'reading_time',
        'target_keyword', 'target_location', 'search_intent',
        'source', 'ai_meta', 'status', 'published_at', 'author_id',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'source' => self::SOURCE_MANUAL,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'faq' => 'array',
            'ai_meta' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model'), 'author_id');
    }

    /**
     * Visible to visitors: published, and past its publish date. A post
     * dated in the future is scheduled, and stays out of sight until then.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('published_at', '>', now());
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->greaterThan(now());
    }

    public function publicPath(): string
    {
        return '/'.trim((string) config('gadya-cms.blog.prefix', 'blog'), '/').'/'.$this->slug;
    }

    protected static function newFactory(): Factory
    {
        return PostFactory::new();
    }
}
