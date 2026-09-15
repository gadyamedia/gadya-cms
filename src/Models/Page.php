<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'gadyacms_pages';

    /** @var list<string> */
    protected $fillable = ['site_id', 'slug', 'title', 'type', 'status', 'sort_order', 'draft', 'published'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => 'content',
        'status' => self::STATUS_PUBLISHED,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft' => 'array',
            'published' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Keep the draft document in step with the columns Filament edits, so
     * the assembled site document and the table listing can never disagree
     * about a page's address, title, type or status.
     */
    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            if ($page->draft === null) {
                return;
            }

            $page->draft = [
                ...$page->draft,
                'slug' => $page->slug,
                'title' => $page->title,
                'type' => $page->type,
                'status' => $page->status,
            ];
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    protected static function newFactory(): Factory
    {
        return PageFactory::new();
    }
}
