<?php

namespace Gadya\Cms\Menus;

use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Models\Menu;
use Gadya\Cms\Models\MenuItem;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * Reads menus for the public site: only published ones for a visitor,
 * drafts too for someone editing or previewing, with hidden items left
 * out and everything in the order it was dragged into.
 */
class FoodMenus
{
    public function __construct(
        private readonly SiteContext $siteContext,
        private readonly EditContext $editor,
        private readonly SiteImage $images,
    ) {}

    public function find(string $slug): ?Menu
    {
        return rescue(fn (): ?Menu => $this->query()->where('slug', $slug)->first(), null, report: false);
    }

    /**
     * @return Collection<int, Menu>
     */
    public function all(): Collection
    {
        return rescue(fn (): Collection => $this->query()->orderBy('sort_order')->orderBy('id')->get(), new Collection, report: false);
    }

    /**
     * The items marked as a special, across every menu a visitor can see.
     *
     * @return Collection<int, MenuItem>
     */
    public function featured(int $limit = 6): Collection
    {
        try {
            return MenuItem::query()
                ->visible()
                ->where('is_featured', true)
                ->whereHas('section.menu', fn (Builder $menu) => $this->scopeMenus($menu))
                ->with('section.menu')
                ->orderBy('sort_order')
                ->limit($limit)
                ->get();
        } catch (QueryException) {
            return new Collection;
        }
    }

    /**
     * The menu in schema.org's words: Menu, MenuSection, MenuItem, and an
     * Offer per price. A sold-out item stays listed but its offer says
     * SoldOut, so nobody is sent to the counter for it.
     *
     * @return array<string, mixed>
     */
    public function structuredData(Menu $menu): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Menu',
            '@id' => url('/').'#'.$menu->anchor(),
            'name' => $menu->name,
            'description' => $menu->description ?: null,
            'hasMenuSection' => $menu->sections
                ->map(fn ($section): array => array_filter([
                    '@type' => 'MenuSection',
                    'name' => $section->name,
                    'description' => $section->description ?: null,
                    'hasMenuItem' => $section->items->map(fn (MenuItem $item): array => $this->itemNode($item))->values()->all() ?: null,
                ], fn ($value): bool => $value !== null))
                ->values()
                ->all() ?: null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemNode(MenuItem $item): array
    {
        $availability = $item->is_sold_out ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';

        $offers = array_map(fn (array $price): array => array_filter([
            '@type' => 'Offer',
            'name' => $price['label'],
            'price' => Price::toDecimal($price['price_cents']),
            'priceCurrency' => Price::currency(),
            'availability' => $availability,
        ], fn ($value): bool => $value !== null), $item->prices());

        $diets = array_values(array_filter(array_column(Dietary::for($item->dietary), 'schema')));

        return array_filter([
            '@type' => 'MenuItem',
            'name' => $item->name,
            'description' => $item->description ?: null,
            'image' => $item->image ? $this->images->url($item->image) : null,
            'suitableForDiet' => $diets === [] ? null : (count($diets) === 1 ? $diets[0] : $diets),
            'offers' => $offers === [] ? null : (count($offers) === 1 ? $offers[0] : $offers),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @return Builder<Menu>
     */
    private function query(): Builder
    {
        return $this->scopeMenus(Menu::query())->with([
            'sections' => fn ($sections) => $sections->with(['items' => fn ($items) => $items->visible()]),
        ]);
    }

    /**
     * @param  Builder<Menu>  $query
     * @return Builder<Menu>
     */
    private function scopeMenus(Builder $query): Builder
    {
        return $query
            ->where('site_id', $this->siteContext->id())
            ->when(! $this->editor->showsDraft(), fn (Builder $published) => $published->where('status', Menu::STATUS_PUBLISHED));
    }
}
