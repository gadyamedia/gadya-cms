<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

/**
 * One of a restaurant's menus - breakfast, lunch, drinks - made of
 * sections, each made of items. Its `key` never changes, so an ordering
 * service added later can hold on to it through any rename.
 */
class Menu extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $table = 'gadyacms_menus';

    /** @var list<string> */
    protected $fillable = ['site_id', 'key', 'name', 'slug', 'description', 'availability', 'status', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $menu): void {
            $menu->key = $menu->key ?: (string) Str::ulid();
            $menu->slug = $menu->slug ?: Str::slug((string) $menu->name);
        });
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<MenuSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasManyThrough<MenuItem, MenuSection, $this> */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(MenuItem::class, MenuSection::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /** The fragment the menu is anchored at on its page, and in its JSON-LD. */
    public function anchor(): string
    {
        return 'menu-'.$this->slug;
    }
}
