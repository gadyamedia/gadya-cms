<?php

namespace Gadya\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A heading on a menu - Bagels, Spreads, Hot drinks - and the items under
 * it, in the order they were dragged into.
 */
class MenuSection extends Model
{
    protected $table = 'gadyacms_menu_sections';

    /** @var list<string> */
    protected $fillable = ['menu_id', 'key', 'name', 'description', 'availability', 'sort_order'];

    /** @var array<string, mixed> */
    protected $attributes = ['sort_order' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $section): void {
            $section->key = $section->key ?: (string) Str::ulid();

            if ($section->sort_order === 0 && $section->menu_id !== null) {
                $section->sort_order = (int) static::query()->where('menu_id', $section->menu_id)->max('sort_order') + 1;
            }
        });
    }

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return HasMany<MenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function anchor(): string
    {
        return ($this->menu?->anchor() ?? 'menu').'-'.(Str::slug((string) $this->name) ?: $this->key);
    }
}
