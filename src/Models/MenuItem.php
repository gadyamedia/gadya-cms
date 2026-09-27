<?php

namespace Gadya\Cms\Models;

use Gadya\Cms\Menus\Price;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Something on a menu. A price is whole cents, never a float; an item
 * sold in sizes carries `variants` instead - each with its own key, label
 * and price - so "Small $3 / Large $4.50" is data, not a sentence.
 *
 * @property list<array{key: string, label: string, price_cents: int|null}>|null $variants
 * @property list<string>|null $dietary
 */
class MenuItem extends Model
{
    protected $table = 'gadyacms_menu_items';

    /** @var list<string> */
    protected $fillable = [
        'menu_section_id', 'key', 'name', 'description', 'price_cents', 'variants', 'dietary',
        'image', 'image_alt', 'availability', 'is_featured', 'is_sold_out', 'is_visible', 'sort_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_featured' => false,
        'is_sold_out' => false,
        'is_visible' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'variants' => 'array',
            'dietary' => 'array',
            'is_featured' => 'boolean',
            'is_sold_out' => 'boolean',
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->key = $item->key ?: (string) Str::ulid();

            if ($item->sort_order === 0 && $item->menu_section_id !== null) {
                $item->sort_order = (int) static::query()->where('menu_section_id', $item->menu_section_id)->max('sort_order') + 1;
            }
        });

        static::saving(function (self $item): void {
            $item->variants = static::normaliseVariants($item->variants);
            $item->dietary = array_values(array_unique(array_filter(array_map('strval', (array) $item->dietary)))) ?: null;
        });
    }

    /** @return BelongsTo<MenuSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForSite(Builder $query, ?int $siteId): Builder
    {
        return $query->whereHas('section.menu', fn (Builder $menu) => $menu->where('site_id', $siteId));
    }

    public function hasVariants(): bool
    {
        return ($this->variants ?? []) !== [];
    }

    /**
     * Every price the item is sold at, as label => cents; the label is
     * null for an item with a single price.
     *
     * @return list<array{key: string|null, label: string|null, price_cents: int}>
     */
    public function prices(): array
    {
        if ($this->hasVariants()) {
            return array_values(array_filter(
                array_map(fn (array $variant): array => ['key' => $variant['key'], 'label' => $variant['label'], 'price_cents' => $variant['price_cents']], $this->variants ?? []),
                fn (array $price): bool => $price['price_cents'] !== null,
            ));
        }

        return $this->price_cents === null ? [] : [['key' => null, 'label' => null, 'price_cents' => $this->price_cents]];
    }

    /**
     * Sizes as they come from a form or an import: a price typed in dollars
     * becomes cents, every size gets a key it keeps, and an empty row is
     * dropped.
     *
     * @param  mixed  $variants
     * @return list<array{key: string, label: string, price_cents: int|null}>|null
     */
    public static function normaliseVariants($variants): ?array
    {
        $normalised = [];

        foreach ((array) $variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $label = trim((string) ($variant['label'] ?? ''));
            $cents = array_key_exists('price', $variant)
                ? Price::toCents($variant['price'])
                : (isset($variant['price_cents']) && $variant['price_cents'] !== '' ? (int) $variant['price_cents'] : null);

            if ($label === '' && $cents === null) {
                continue;
            }

            $normalised[] = [
                'key' => filled($variant['key'] ?? null) ? (string) $variant['key'] : (string) Str::ulid(),
                'label' => $label,
                'price_cents' => $cents,
            ];
        }

        return $normalised === [] ? null : $normalised;
    }
}
