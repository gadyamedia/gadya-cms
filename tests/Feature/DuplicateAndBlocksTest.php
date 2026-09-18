<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Content\PageTypes;
use Gadya\Cms\Content\SiteBlocks;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\Blocks;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Filament\Resources\Pages\Pages\EditPage;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Filament\Resources\Posts\Pages\ListPosts;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Services\ManagePages;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;
use RuntimeException;

class DuplicateAndBlocksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_duplicating_a_page_copies_everything_but_leaves_the_copy_hidden(): void
    {
        $page = Page::query()->where('slug', 'home')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callAction(TestAction::make('duplicate')->table($page), ['slug' => 'home-two', 'title' => 'Second home']);

        $draft = app(SiteContentRepository::class)->draft();
        $copy = $draft['pages']['home-two'];

        $this->assertSame('Second home', $copy['title']);
        $this->assertSame('archived', $copy['status'], 'A half-finished copy is never on the site.');
        $this->assertSame($draft['pages']['home']['sections'], $copy['sections']);
        $this->assertSame('Welcome to the site', $copy['heading']);
        $this->assertArrayNotHasKey('home-two', app(SiteContentRepository::class)->published()['pages']);
    }

    public function test_a_copy_is_offered_an_address_nobody_holds(): void
    {
        $pages = app(ManagePages::class);

        $this->assertSame('about-copy', $pages->availableSlug('about-copy'));

        $pages->duplicate('about', 'about-copy');

        $this->assertSame('about-copy-2', $pages->availableSlug('about-copy'));

        Page::query()->where('slug', 'about-copy')->firstOrFail()->delete();

        $this->assertSame('about-copy-2', $pages->availableSlug('about-copy'), 'A page in the trash still holds its address.');
    }

    public function test_duplicating_refuses_an_address_that_is_taken_or_reserved(): void
    {
        $pages = app(ManagePages::class);

        $this->expectException(RuntimeException::class);

        $pages->duplicate('about', 'pricing');
    }

    public function test_duplicating_an_article_makes_a_draft_with_the_same_filing(): void
    {
        $term = Term::query()->create(['name' => 'Party ideas']);
        $post = Post::factory()->published()->create(['slug' => 'the-original', 'title' => 'The original']);
        $post->terms()->attach($term);

        Livewire::actingAs($this->editor())
            ->test(ListPosts::class)
            ->callAction(TestAction::make('duplicate')->table($post))
            ->assertNotified('Copied');

        $copy = Post::query()->where('slug', 'the-original-copy')->firstOrFail();

        $this->assertSame('The original (copy)', $copy->title);
        $this->assertSame(Post::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertSame(['Party ideas'], $copy->terms()->pluck('name')->all());
    }

    public function test_a_section_can_be_saved_as_a_block_and_dropped_into_another_page(): void
    {
        $blocks = app(SiteBlocks::class);
        $section = ['type' => 'cards', 'title' => 'What people say', 'items' => [['title' => 'Pat', 'text' => 'Wonderful.']]];

        $key = $blocks->save('Testimonials', $section);

        $this->assertSame(['Testimonials'], array_values($blocks->options()));
        $this->assertSame($section, $blocks->section($key));

        $page = Page::query()->where('slug', 'about')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(EditPage::class, ['record' => $page->getKey()])
            ->callAction(TestAction::make('insertBlock')->schemaComponent('sections'), ['block' => $key])
            ->call('save')
            ->assertHasNoFormErrors();

        $sections = app(SiteContentRepository::class)->draft()['pages']['about']['sections'];

        $this->assertSame('What people say', end($sections)['title']);
    }

    public function test_a_block_is_a_copy_so_editing_the_page_leaves_the_block_alone(): void
    {
        $blocks = app(SiteBlocks::class);
        $key = $blocks->save('Testimonials', ['type' => 'cards', 'title' => 'What people say']);

        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['sections'] = [$blocks->section($key)];
        $document['pages']['about']['sections'][0]['title'] = 'Changed on the page';
        $repository->saveDraft($document);

        $this->assertSame('What people say', $blocks->section($key)['title']);
    }

    public function test_blocks_can_be_renamed_and_removed_from_the_panel(): void
    {
        $blocks = app(SiteBlocks::class);
        $key = $blocks->save('Testimonials', ['type' => 'cards', 'title' => 'What people say', 'items' => [['title' => 'Pat']]]);

        Livewire::actingAs($this->editor())
            ->test(Blocks::class)
            ->assertSee('Testimonials')
            ->assertSee('Cards · 1 item')
            ->callAction(TestAction::make('rename')->arguments(['key' => $key]), ['label' => 'What people say'])
            ->assertNotified('Renamed');

        $this->assertSame(['What people say'], array_values(app(SiteBlocks::class)->options()));

        Livewire::actingAs($this->editor())
            ->test(Blocks::class)
            ->callAction(TestAction::make('forget')->arguments(['key' => $key]))
            ->assertNotified('Removed');

        $this->assertSame([], app(SiteBlocks::class)->all());
    }

    public function test_a_page_type_may_carry_its_own_fields(): void
    {
        $editor = $this->editor();
        config(['gadya-cms.pages.types' => [
            'content' => ['label' => 'Ordinary page', 'fields' => ['heading' => ['label' => 'Heading', 'type' => 'text']]],
            'home' => ['label' => 'Front page', 'fields' => [
                'heading' => ['label' => 'Big heading', 'type' => 'text'],
                'strapline' => ['label' => 'Strapline', 'type' => 'textarea'],
            ]],
        ]]);

        $home = Page::query()->where('slug', 'home')->firstOrFail();
        $about = Page::query()->where('slug', 'about')->firstOrFail();

        $this->actingAs($editor)
            ->get(PageResource::getUrl('edit', ['record' => $home]))
            ->assertOk()
            ->assertSee('Big heading')
            ->assertSee('Strapline')
            ->assertSee('Front page');

        $this->actingAs($editor)
            ->get(PageResource::getUrl('edit', ['record' => $about]))
            ->assertOk()
            ->assertDontSee('Strapline');
    }

    public function test_a_type_that_says_nothing_keeps_the_site_wide_fields(): void
    {
        config(['gadya-cms.pages.types' => ['home' => ['label' => 'Front page']]]);

        $types = app(PageTypes::class);

        $this->assertSame(array_keys((array) config('gadya-cms.pages.content_fields')), array_keys($types->fieldsFor('home')));
        $this->assertSame('Front page', $types->label('home'));
        $this->assertSame('Legal', $types->label('legal'), 'An unnamed type is called after itself.');
    }
}
