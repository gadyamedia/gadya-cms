<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Content\MediaUsage;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\EditMenuItem;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\ListMenuItems;
use Gadya\Cms\Filament\Resources\Menus\Pages\CreateMenu;
use Gadya\Cms\Menus\Price;
use Gadya\Cms\Models\Menu;
use Gadya\Cms\Models\MenuItem;
use Gadya\Cms\Models\MenuSection;
use Gadya\Cms\Models\Site;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\Fixtures\User;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * A restaurant's menus: sections of items with prices in whole cents,
 * sizes, dietary marks, specials and a sold-out switch that takes effect
 * the moment the counter flips it.
 */
class FoodMenusTest extends TestCase
{
    private Menu $menu;

    private MenuSection $bagels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        $this->menu = Menu::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'name' => 'Breakfast',
            'availability' => 'Weekdays until 11am',
            'status' => Menu::STATUS_PUBLISHED,
        ]);

        $this->bagels = $this->menu->sections()->create(['name' => 'Bagels']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function item(array $attributes = []): MenuItem
    {
        return $this->bagels->items()->create([
            'name' => 'Everything bagel',
            'description' => 'With scallion cream cheese.',
            'price_cents' => 450,
            'dietary' => ['vegetarian'],
            ...$attributes,
        ]);
    }

    public function test_prices_are_whole_cents_from_whatever_was_typed(): void
    {
        $this->assertSame(450, Price::toCents('4.50'));
        $this->assertSame(450, Price::toCents('$4.5'));
        $this->assertSame(1200, Price::toCents('12'));
        $this->assertSame(1999, Price::toCents('19.99'));
        $this->assertNull(Price::toCents(''));
        $this->assertNull(Price::toCents('four'));

        $this->assertSame('$4.50', Price::format(450));
        $this->assertSame('$12', Price::format(1200));
        $this->assertSame('0.05', Price::toDecimal(5));
    }

    public function test_every_menu_section_item_and_size_gets_a_key_that_survives_a_rename(): void
    {
        $item = $this->item(['price_cents' => null, 'variants' => [['label' => 'Small', 'price' => '3'], ['label' => 'Large', 'price' => '4.50']]]);

        $this->assertNotEmpty($this->menu->key);
        $this->assertNotEmpty($this->bagels->key);
        $this->assertSame([300, 450], array_column($item->variants, 'price_cents'));

        $keys = [$item->key, ...array_column($item->variants, 'key')];

        $item->update(['name' => 'Everything', 'variants' => [...$item->variants]]);

        $this->assertSame($keys, [$item->fresh()->key, ...array_column($item->fresh()->variants, 'key')]);
    }

    public function test_the_menu_renders_sections_prices_sizes_marks_and_what_is_sold_out(): void
    {
        $this->item(['is_featured' => true]);
        $this->item(['name' => 'Coffee', 'price_cents' => null, 'dietary' => ['vegan', 'gluten-free'], 'variants' => [['label' => 'Small', 'price_cents' => 250], ['label' => 'Large', 'price_cents' => 325]], 'is_sold_out' => true]);
        $this->item(['name' => 'Pumpkin bagel', 'is_visible' => false]);

        $html = Blade::render('<x-gadya-cms::menu menu="breakfast" />');

        $this->assertStringContainsString('<h2 class="cms-menu__title" id="menu-breakfast-title">Breakfast</h2>', $html);
        $this->assertStringContainsString('Weekdays until 11am', $html);
        $this->assertStringContainsString('<h3 class="cms-menu__section-title"', $html);
        $this->assertStringContainsString('Everything bagel', $html);
        $this->assertStringContainsString('$4.50', $html);
        $this->assertStringContainsString('<dt>Large</dt>', $html);
        $this->assertStringContainsString('$3.25', $html);
        $this->assertStringContainsString('cms-menu-item--sold-out', $html);
        $this->assertStringContainsString('Sold out', $html);
        $this->assertStringContainsString('cms-menu-item--featured', $html);
        $this->assertStringContainsString('>Vegan</span>', $html, 'A screen reader hears the whole word, not "VG".');
        $this->assertStringContainsString('Key to the dietary marks', $html);
        $this->assertStringNotContainsString('Pumpkin bagel', $html, 'A hidden item is not on the menu.');
    }

    public function test_the_menu_describes_itself_to_search_engines(): void
    {
        $this->item();
        $this->item(['name' => 'Coffee', 'price_cents' => null, 'dietary' => ['vegan', 'contains-nuts'], 'variants' => [['label' => 'Small', 'price_cents' => 250], ['label' => 'Large', 'price_cents' => 325]], 'is_sold_out' => true]);

        $html = Blade::render('<x-gadya-cms::menu menu="breakfast" />');

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
        $node = json_decode($match[1], true);

        $this->assertSame('Menu', $node['@type']);
        $this->assertStringEndsWith('#menu-breakfast', $node['@id']);
        $this->assertSame('MenuSection', $node['hasMenuSection'][0]['@type']);

        [$bagel, $coffee] = $node['hasMenuSection'][0]['hasMenuItem'];

        $this->assertSame('MenuItem', $bagel['@type']);
        $this->assertSame(['@type' => 'Offer', 'price' => '4.50', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock'], $bagel['offers']);
        $this->assertSame('https://schema.org/VegetarianDiet', $bagel['suitableForDiet']);

        $this->assertSame('Large', $coffee['offers'][1]['name']);
        $this->assertSame('https://schema.org/SoldOut', $coffee['offers'][1]['availability']);
        $this->assertSame('https://schema.org/VeganDiet', $coffee['suitableForDiet'], 'An allergen warning has no schema.org diet.');
    }

    public function test_a_draft_menu_is_only_seen_by_someone_editing(): void
    {
        $this->item();
        $this->menu->update(['status' => Menu::STATUS_DRAFT]);

        $this->assertSame('', trim(Blade::render('<x-gadya-cms::menu menu="breakfast" />')));

        $this->actingAs($this->editor());
        session([EditContext::SESSION_KEY => true]);

        $html = Blade::render('<x-gadya-cms::menu menu="breakfast" />');

        $this->assertStringContainsString('Everything bagel', $html);
        $this->assertStringContainsString('Change this menu in the admin', $html);
        $this->assertStringContainsString('/admin/menus/'.$this->menu->getKey().'/edit', $html);
        $this->assertStringNotContainsString('application/ld+json', $html, 'A draft is not described to search engines.');
    }

    public function test_specials_are_gathered_from_every_published_menu(): void
    {
        $this->item(['name' => 'Lox special', 'is_featured' => true]);
        $this->item(['name' => 'Plain bagel']);

        $html = Blade::render('<x-gadya-cms::menu-specials heading="Today’s specials" />');

        $this->assertStringContainsString('Today’s specials', $html);
        $this->assertStringContainsString('Lox special', $html);
        $this->assertStringNotContainsString('Plain bagel', $html);
    }

    public function test_an_item_is_added_in_the_panel_with_prices_in_dollars_kept_as_cents(): void
    {
        Livewire::actingAs($this->editor())
            ->test(CreateMenuItem::class)
            ->fillForm([
                'menu_section_id' => $this->bagels->getKey(),
                'name' => 'Iced coffee',
                'price_cents' => '',
                'variants' => [
                    ['label' => 'Medium', 'price_cents' => '3.75'],
                    ['label' => 'Large', 'price_cents' => '4.25'],
                ],
                'dietary' => ['vegan'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $item = MenuItem::query()->where('name', 'Iced coffee')->firstOrFail();

        $this->assertNull($item->price_cents);
        $this->assertSame([375, 425], array_column($item->variants, 'price_cents'));
        $this->assertNotEmpty($item->variants[0]['key']);
        $this->assertSame(['vegan'], $item->dietary);

        Livewire::actingAs($this->editor())
            ->test(EditMenuItem::class, ['record' => $item->getKey()])
            ->assertFormSet(fn (array $state): bool => collect($state['variants'])->pluck('price_cents')->values()->all() === ['3.75', '4.25'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([375, 425], array_column($item->fresh()->variants, 'price_cents'), 'Saving again changes nothing.');
        $this->assertSame(array_column($item->variants, 'key'), array_column($item->fresh()->variants, 'key'));
    }

    public function test_the_counter_marks_items_sold_out_and_brings_everything_back_in(): void
    {
        $bagel = $this->item();
        $coffee = $this->item(['name' => 'Coffee', 'is_sold_out' => true]);

        Livewire::actingAs($this->editor())
            ->test(ListMenuItems::class)
            ->assertCanSeeTableRecords([$bagel, $coffee])
            ->call('updateTableColumnState', 'is_sold_out', (string) $bagel->getKey(), true);

        $this->assertTrue($bagel->fresh()->is_sold_out);
        $this->assertStringContainsString('Sold out', Blade::render('<x-gadya-cms::menu menu="breakfast" />'), 'Live at once: there is nothing to publish.');

        Livewire::actingAs($this->editor())
            ->test(ListMenuItems::class)
            ->callAction(TestAction::make('allBackIn')->table());

        $this->assertFalse($bagel->fresh()->is_sold_out);
        $this->assertFalse($coffee->fresh()->is_sold_out);
    }

    public function test_items_are_dragged_into_order(): void
    {
        $first = $this->item(['name' => 'First']);
        $second = $this->item(['name' => 'Second']);

        Livewire::actingAs($this->editor())
            ->test(ListMenuItems::class)
            ->call('reorderTable', [(string) $second->getKey(), (string) $first->getKey()]);

        $this->assertLessThan($first->fresh()->sort_order, $second->fresh()->sort_order);
        $this->assertStringContainsString('Second', explode('First', Blade::render('<x-gadya-cms::menu menu="breakfast" />'))[0]);
    }

    public function test_a_menu_is_created_for_this_site_and_another_sites_items_stay_out_of_the_list(): void
    {
        Livewire::actingAs($this->editor())
            ->test(CreateMenu::class)
            ->fillForm(['name' => 'Lunch', 'slug' => 'lunch', 'status' => Menu::STATUS_PUBLISHED])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(app(SiteContext::class)->id(), Menu::query()->where('slug', 'lunch')->value('site_id'));

        $elsewhere = Menu::query()->create(['site_id' => Site::factory()->create()->getKey(), 'name' => 'Theirs']);
        $theirs = $elsewhere->sections()->create(['name' => 'Soup'])->items()->create(['name' => 'Their soup']);

        Livewire::actingAs($this->editor())
            ->test(ListMenuItems::class)
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_menu_items_photo_counts_as_in_use(): void
    {
        $this->item(['image' => 'bagel.webp']);

        $this->assertContains('Menu item: Everything bagel', app(MediaUsage::class)->map()['bagel.webp'] ?? []);
    }

    public function test_the_menu_screens_need_the_content_ability(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get('/admin/menus')->assertOk();
        $this->actingAs($editor)->get('/admin/menu-items')->assertOk();
    }

    public function test_someone_without_the_content_ability_cannot_open_them(): void
    {
        $contributor = User::query()->create(['name' => 'Cai Contributor', 'email' => 'cai@example.com', 'password' => 'password', 'role' => 'contributor']);

        $this->actingAs($contributor)->get('/admin/menu-items')->assertForbidden();
    }
}
