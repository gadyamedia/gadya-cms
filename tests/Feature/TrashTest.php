<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class TrashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_a_deleted_page_leaves_the_site_but_can_be_put_back(): void
    {
        $page = Page::query()->where('slug', 'about')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callAction(TestAction::make('delete')->table($page));

        $this->assertSoftDeleted($page);
        $this->assertArrayNotHasKey('about', app(SiteContentRepository::class)->draft()['pages']);
        $this->assertArrayNotHasKey('about', app(SiteContentRepository::class)->published()['pages']);

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->filterTable('trashed', '0')
            ->callAction(TestAction::make('restore')->table($page));

        $this->assertNotSoftDeleted($page);
        $this->assertSame('About us', app(SiteContentRepository::class)->draft()['pages']['about']['title']);
    }

    public function test_a_page_deleted_and_published_comes_back_editable(): void
    {
        $repository = app(SiteContentRepository::class);

        $document = $repository->draft();
        unset($document['pages']['about']);
        $repository->saveDraft($document);
        app(PublishSiteContent::class)->handle();

        $page = Page::onlyTrashed()->where('slug', 'about')->firstOrFail();
        $this->assertNull($page->draft, 'Publishing a removal empties the draft copy.');

        $page->restoreToDraft();

        $this->assertSame('About us', $repository->draft()['pages']['about']['title'], 'Restoring gives the page its draft back.');
    }

    public function test_the_address_of_a_trashed_page_can_be_taken_again(): void
    {
        $page = Page::query()->where('slug', 'about')->firstOrFail();
        $page->delete();

        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about'] = ['title' => 'A new about', 'type' => 'content', 'heading' => 'New', 'sections' => []];
        $repository->saveDraft($document);

        $this->assertSame('A new about', $repository->draft()['pages']['about']['title']);
        $this->assertSame(1, Page::withTrashed()->where('slug', 'about')->count(), 'One row per address, always.');
        $this->assertNotSoftDeleted($page->fresh());
    }

    public function test_the_trash_is_emptied_of_what_nobody_came_back_for(): void
    {
        $old = Page::query()->where('slug', 'about')->firstOrFail();
        $recent = Page::query()->where('slug', 'pricing')->firstOrFail();
        $post = Post::factory()->create();

        $old->delete();
        $recent->delete();
        $post->delete();

        $old->forceFill(['deleted_at' => now()->subDays(45)])->saveQuietly();
        $post->forceFill(['deleted_at' => now()->subDays(45)])->saveQuietly();

        $this->artisan('gadya-cms:prune-trash')->expectsOutputToContain('1 pages and 1 articles')->assertSuccessful();

        $this->assertSame(0, Page::withTrashed()->where('slug', 'about')->count());
        $this->assertSame(1, Page::onlyTrashed()->where('slug', 'pricing')->count(), 'Inside the window, it waits.');
        $this->assertSame(0, Post::withTrashed()->count());
    }

    public function test_the_list_hides_the_trash_until_it_is_asked_for(): void
    {
        $kept = Page::query()->where('slug', 'about')->firstOrFail();
        $binned = Page::query()->where('slug', 'pricing')->firstOrFail();
        $binned->delete();

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->assertCanSeeTableRecords([$kept])
            ->assertCanNotSeeTableRecords([$binned])
            ->filterTable('trashed', '0')
            ->assertCanSeeTableRecords([$binned])
            ->assertCanNotSeeTableRecords([$kept]);
    }
}
