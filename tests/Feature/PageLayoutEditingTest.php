<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Support\SafeLink;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * The client lays out a page from the page itself: she adds a section in a design she picks at the spot
 * she picks, moves and removes sections, grows a gallery, and puts her own links in the header, the footer
 * and on any card. All of it lands in the draft.
 */
class PageLayoutEditingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument([
            'pages' => [
                'home' => ['title' => 'Home', 'heading' => 'Welcome'],
                'rentals' => ['title' => 'Rentals', 'sections' => [
                    ['title' => 'Tables', 'type' => 'cards', 'items' => [['title' => 'Round table']]],
                    ['title' => 'Bouncers', 'type' => 'cards', 'items' => []],
                ]],
            ],
        ]);

        $this->actingAs($this->editor());
    }

    public function test_a_section_is_added_in_the_chosen_design_at_the_chosen_spot_with_a_sample_item(): void
    {
        $this->change(['operation' => 'add-section', 'page' => 'rentals', 'type' => 'text-grid', 'position' => 1])->assertOk();

        $sections = $this->draft()['pages']['rentals']['sections'];
        $this->assertSame(['Tables', 'New section', 'Bouncers'], array_column($sections, 'title'));
        $this->assertSame('text-grid', $sections[1]['type']);
        $this->assertSame('New item', $sections[1]['items'][0]['title']);
        $this->assertNotEmpty($sections[1]['items'][0]['key']);
    }

    public function test_a_page_that_never_had_sections_gets_its_first(): void
    {
        $this->change(['operation' => 'add-section', 'page' => 'home', 'type' => 'gallery', 'position' => 0])->assertOk();

        $this->assertSame([['title' => 'New section', 'type' => 'gallery', 'images' => []]], $this->draft()['pages']['home']['sections']);
    }

    public function test_only_the_designs_on_offer_can_be_added(): void
    {
        $this->change(['operation' => 'add-section', 'page' => 'rentals', 'type' => 'application', 'position' => 0])
            ->assertUnprocessable()
            ->assertJsonPath('message', '[application] cannot be added to this page.');

        $this->assertCount(2, $this->draft()['pages']['rentals']['sections']);
    }

    public function test_sections_move_one_step_and_are_removed(): void
    {
        $this->change(['operation' => 'move-section', 'page' => 'rentals', 'index' => 0, 'step' => 1])->assertOk();
        $this->assertSame(['Bouncers', 'Tables'], array_column($this->draft()['pages']['rentals']['sections'], 'title'));

        $this->change(['operation' => 'move-section', 'page' => 'rentals', 'index' => 0, 'step' => -1])->assertUnprocessable();

        $this->change(['operation' => 'remove-section', 'page' => 'rentals', 'index' => 0])->assertOk();
        $this->assertSame(['Tables'], array_column($this->draft()['pages']['rentals']['sections'], 'title'));
    }

    public function test_a_gallery_grows_an_empty_slot_that_the_photo_library_fills(): void
    {
        $this->change(['operation' => 'add-section', 'page' => 'rentals', 'type' => 'gallery', 'position' => 2]);

        $this->change(['operation' => 'add-photo', 'section_path' => 'pages.rentals.sections.2'])->assertOk();
        $this->inline('pages.rentals.sections.2.images.0', 'party.jpg')->assertOk();

        $this->assertSame(['party.jpg'], $this->draft()['pages']['rentals']['sections'][2]['images']);
        $this->change(['operation' => 'add-photo', 'section_path' => 'pages.rentals.sections.0'])->assertUnprocessable();
    }

    public function test_nothing_changes_shape_while_someone_else_is_editing(): void
    {
        app(EditingLock::class)->acquire($this->administrator());

        $this->change(['operation' => 'remove-section', 'page' => 'rentals', 'index' => 0])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Ada Admin is editing right now. Wait until they finish.');

        $this->assertCount(2, $this->draft()['pages']['rentals']['sections']);
    }

    public function test_a_visitor_cannot_change_the_layout(): void
    {
        $this->actingAs($this->visitor());

        $this->change(['operation' => 'add-link', 'list' => 'footer'])->assertForbidden();
    }

    public function test_links_are_added_typed_in_place_and_removed(): void
    {
        $this->change(['operation' => 'add-link', 'list' => 'footer'])->assertOk();
        $this->inline('links.footer.0.label', 'Sign the waiver')->assertOk();
        $this->inline('links.footer.0.url', 'waiver.example.com/fun')->assertOk();

        $this->assertSame([['label' => 'Sign the waiver', 'url' => 'waiver.example.com/fun']], $this->draft()['links']['footer']);

        $this->change(['operation' => 'remove-link', 'list' => 'footer', 'index' => 0])->assertOk();
        $this->assertSame([], $this->draft()['links']['footer']);
    }

    public function test_a_card_is_given_a_button_without_the_site_listing_the_fields(): void
    {
        $this->inline('pages.rentals.sections.0.items.0.link_label', 'Book it')->assertOk();
        $this->inline('pages.rentals.sections.0.items.0.link_url', '/contact')->assertOk();

        $this->assertSame(['title' => 'Round table', 'link_label' => 'Book it', 'link_url' => '/contact'], $this->draft()['pages']['rentals']['sections'][0]['items'][0]);
    }

    public function test_visitors_see_only_finished_safe_links(): void
    {
        $site = ['links' => ['footer' => [
            ['label' => 'Sign the waiver', 'url' => 'waiver.example.com/fun'],
            ['label' => 'Sneaky', 'url' => 'javascript:alert(1)'],
            ['label' => 'Unfinished', 'url' => ''],
        ]]];

        $html = Blade::render('<x-gadya-cms::links list="footer" :site="$site" link-class="footer-link" />', ['site' => $site]);

        $this->assertMatchesRegularExpression('~<a class="footer-link" href="https://waiver\.example\.com/fun"\s+target="_blank" rel="noopener"\s*>Sign the waiver</a>~', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('Sneaky', $html);
        $this->assertStringNotContainsString('Unfinished', $html);
        $this->assertStringNotContainsString('data-cms-region', $html);
    }

    public function test_an_item_button_shows_once_it_has_words_and_a_safe_address(): void
    {
        $render = fn (array $item): string => Blade::render('<x-gadya-cms::item-button :item="$item" path="sections.0.items.0" class="button" />', ['item' => $item]);

        $this->assertMatchesRegularExpression('~^<a class="button" href="/contact"\s*>Book it</a>$~', trim($render(['link_label' => 'Book it', 'link_url' => '/contact'])));
        $this->assertSame('', trim($render(['link_label' => 'Book it', 'link_url' => 'javascript:void(0)'])));
        $this->assertSame('', trim($render(['link_label' => '', 'link_url' => '/contact'])));
    }

    public function test_safe_links_allow_only_web_site_email_and_phone_addresses(): void
    {
        $this->assertSame('https://example.com/a', SafeLink::href('https://example.com/a'));
        $this->assertSame('https://example.com/waiver', SafeLink::href('example.com/waiver'));
        $this->assertSame('/contact', SafeLink::href('/contact'));
        $this->assertSame('tel:+16465550100', SafeLink::href('tel:+16465550100'));
        $this->assertNull(SafeLink::href('javascript:alert(1)'));
        $this->assertNull(SafeLink::href('//evil.example.com'));
        $this->assertNull(SafeLink::href('  '));
    }

    public function test_the_toolbar_draws_the_design_picker_and_the_layout_controls_for_the_page(): void
    {
        $this->editorRoute('pages.rentals', '<x-gadya-cms::add-section :position="0" /><x-gadya-cms::section-tools :index="0" first />');

        $this->withSession([EditContext::SESSION_KEY => true])->get('/__layout')
            ->assertOk()
            ->assertSee('data-cms-section-picker', false)
            ->assertSee('Photo cards')
            ->assertDontSee('data-cms-section-type="application"', false)
            ->assertSee('data-cms-section-add="0" data-cms-page="rentals"', false)
            ->assertSee('data-cms-section-remove="0" data-cms-page="rentals"', false);
    }

    public function test_the_toolbar_says_when_changes_went_live(): void
    {
        $this->editorRoute('pages.rentals', '');

        $this->withSession([EditContext::SESSION_KEY => true, 'status' => 'Your changes are now live.'])->get('/__layout')
            ->assertSee('Your changes are now live.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function change(array $payload): TestResponse
    {
        return $this->withSession([EditContext::SESSION_KEY => true])->postJson(route('gadya-cms.structure.update'), $payload);
    }

    private function inline(string $path, string $value): TestResponse
    {
        return $this->withSession([EditContext::SESSION_KEY => true])->postJson(route('gadya-cms.inline.update'), ['path' => $path, 'value' => $value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(): array
    {
        return app(SiteContentRepository::class)->draft();
    }

    private function editorRoute(string $basePath, string $body): void
    {
        Route::middleware('web')->get('/__layout', function () use ($basePath, $body): string {
            $editor = app(EditContext::class);
            $editor->boot();
            $editor->for($basePath);

            return Blade::render($body.'@cmsToolbar');
        });
    }
}
