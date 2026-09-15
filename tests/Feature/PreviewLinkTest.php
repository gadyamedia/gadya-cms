<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\PreviewLink;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class PreviewLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();

        /*
         * The fixture host has no page routes of its own; one is enough to
         * prove which document a previewer is served.
         */
        Route::middleware('web')->get('/about', function (SiteContentRepository $repository): string {
            app(EditContext::class)->boot();

            return (string) $repository->forRequest()['pages']['about']['heading'];
        });
    }

    private function draftAbout(string $heading): void
    {
        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['heading'] = $heading;
        $repository->saveDraft($document);
    }

    public function test_a_signed_link_shows_the_draft_to_someone_with_no_account(): void
    {
        $this->draftAbout('Draft heading');

        $this->get('/about')->assertSee('Who we are');

        $link = app(PreviewLink::class)->for('/about');

        $this->get($link)->assertRedirect('/about');
        $this->get('/about')
            ->assertSee('Draft heading')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $link = app(PreviewLink::class)->for('/about');

        $this->get(str_replace('path=%2Fabout', 'path=%2Fpricing', $link))->assertForbidden();

        $this->travel(4)->days();
        $this->get($link)->assertForbidden();
    }

    public function test_the_preview_ends_when_the_link_would_have_expired_or_the_viewer_stops(): void
    {
        $this->draftAbout('Draft heading');
        $this->get(app(PreviewLink::class)->for('/about'));

        $this->delete('/cms/preview', ['path' => 'about'])->assertRedirect('/about');
        $this->get('/about')->assertSee('Who we are');

        $this->get(app(PreviewLink::class)->for('/about'));
        $this->travel(4)->days();
        $this->get('/about')->assertSee('Who we are');
    }

    public function test_a_draft_article_can_be_previewed_the_same_way(): void
    {
        $post = Post::factory()->create(['slug' => 'coming-soon', 'title' => 'Coming soon']);

        $this->get('/blog/coming-soon')->assertNotFound();
        $this->get(app(PreviewLink::class)->for($post->publicPath()))->assertRedirect('/blog/coming-soon');
        $this->get('/blog/coming-soon')->assertOk()->assertSee('Coming soon')->assertSee('Previewing a draft');
    }
}
