<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    /*
     * A deleted page goes to the trash: gone from the site and the panel,
     * but restorable until it is pruned. Both copies stay on the row, so a
     * restore brings back exactly what was there.
     */
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'gadyacms_pages';

    /** @var list<string> */
    protected $fillable = ['site_id', 'slug', 'title', 'type', 'status', 'sort_order', 'draft', 'published', 'deleted_by'];

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

    /**
     * A page removed from the draft and then published has no draft left;
     * restoring it would leave it live but uneditable, so the published
     * copy becomes the draft again.
     */
    public function restoreToDraft(): void
    {
        /*
         * Read the row again first: the instance in hand may predate the
         * deletion - a table action hands back the model as it was listed -
         * and restoring a model that does not know it was deleted saves
         * nothing at all.
         */
        $this->refresh();

        if ($this->trashed()) {
            $this->restore();
        }

        if ($this->draft === null && is_array($this->published)) {
            $this->forceFill(['draft' => $this->published])->save();
        }

        if ($this->deleted_by !== null) {
            $this->forceFill(['deleted_by' => null])->save();
        }
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
