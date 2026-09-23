<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Filament\Resources\Pages\Pages\EditPage;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

/**
 * An item carries a key that survives renames and reorders, so a site can
 * sync its cards to another system and still know which one is which.
 */
class ItemKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['sections'] = [
            ['title' => 'Add ons', 'type' => 'cards', 'items' => [['key' => '01JADDON', 'title' => 'Slime']]],
        ];
        $repository->saveDraft($document);
    }

    public function test_an_item_added_on_the_page_gets_a_key(): void
    {
        $this->actingAs($this->editor())
            ->withSession([EditContext::SESSION_KEY => true])
            ->postJson(route('gadya-cms.structure.update'), [
                'operation' => 'add-item',
                'section_path' => 'pages.about.sections.0',
                'item' => ['title' => 'New item'],
            ])
            ->assertOk();

        $items = app(SiteContentRepository::class)->draft()['pages']['about']['sections'][0]['items'];

        $this->assertSame('New item', $items[1]['title']);
        $this->assertNotEmpty($items[1]['key']);
        $this->assertNotSame($items[0]['key'], $items[1]['key']);
    }

    public function test_saving_the_page_in_the_admin_keeps_each_items_key(): void
    {
        $page = Page::query()->where('slug', 'about')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $item = app(SiteContentRepository::class)->draft()['pages']['about']['sections'][0]['items'][0];

        $this->assertSame('01JADDON', $item['key']);
        $this->assertSame('Slime', $item['title']);
    }
}
