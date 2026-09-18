<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\NavigationTree;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\Navigation;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class MenusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_a_site_with_one_menu_edits_it_where_it_always_did(): void
    {
        $this->assertSame(['primary' => 'Main menu'], NavigationTree::menus());

        Livewire::actingAs($this->editor())
            ->test(Navigation::class)
            ->assertFormSet(fn (array $state): bool => ($state['nav'][0]['label'] ?? null) === 'Home')
            ->fillForm(['nav' => [['label' => 'Start here', 'slug' => 'home', 'side' => 'start']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $document = app(SiteContentRepository::class)->draft();

        $this->assertSame('Start here', $document['nav'][0]['label']);
        $this->assertArrayNotHasKey('menus', $document, 'One menu leaves the document exactly as it was.');
    }

    public function test_a_second_menu_is_edited_on_the_same_screen_and_kept_apart(): void
    {
        config(['gadya-cms.navigation.menus' => ['primary' => 'Main menu', 'footer' => 'Footer menu']]);

        Livewire::actingAs($this->editor())
            ->test(Navigation::class)
            ->assertSee('Footer menu')
            ->fillForm([
                'nav' => [['label' => 'Home', 'slug' => 'home', 'side' => 'start']],
                'menus.footer' => [
                    ['label' => 'Pricing', 'slug' => 'pricing', 'side' => 'start'],
                    ['label' => 'About', 'slug' => 'about', 'side' => 'start'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $document = app(SiteContentRepository::class)->draft();
        $tree = app(NavigationTree::class);

        $this->assertSame(['Home'], array_column($document['nav'], 'label'));
        $this->assertSame(['Pricing', 'About'], array_column($document['menus']['footer'], 'label'));
        $this->assertSame(['Pricing', 'About'], array_column($tree->forMenu($document, 'footer'), 'label'));
        $this->assertSame(['Home'], array_column($tree->forMenu($document), 'label'), 'The main menu is still the one at nav.');
    }

    public function test_an_unknown_menu_reads_as_empty_rather_than_failing(): void
    {
        $this->assertSame([], app(NavigationTree::class)->forMenu(app(SiteContentRepository::class)->draft(), 'nowhere'));
    }
}
