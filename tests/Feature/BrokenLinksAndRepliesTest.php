<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\Emails;
use Gadya\Cms\Filament\Resources\BrokenLinks\Pages\ListBrokenLinks;
use Gadya\Cms\Forms\AutoReplies;
use Gadya\Cms\Models\BrokenLink;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class BrokenLinksAndRepliesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_an_address_a_visitor_asks_for_and_does_not_get_is_noted_once_and_counted(): void
    {
        $this->get('/a-page-that-never-was')->assertNotFound();
        $this->from('https://elsewhere.example/blog')->get('/a-page-that-never-was')->assertNotFound();

        $link = BrokenLink::query()->firstOrFail();

        $this->assertSame('/a-page-that-never-was', $link->url);
        $this->assertSame(BrokenLink::VISITED, $link->kind);
        $this->assertSame(2, $link->hits, 'The same dead address is one entry, not two.');
        $this->assertSame('elsewhere.example', $link->referrer_host);
    }

    public function test_missing_files_and_panel_addresses_are_not_worth_noting(): void
    {
        $this->get('/old-favicon.ico')->assertNotFound();
        $this->get('/admin/nothing-here');

        $this->assertSame(0, BrokenLink::query()->count());
    }

    public function test_the_checker_finds_links_the_site_makes_to_pages_that_are_gone(): void
    {
        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['description'] = 'See <a href="/pricing">pricing</a> and <a href="/gone-away">the old one</a>.';
        $this->publishDocument($document);

        Post::factory()->published()->create([
            'title' => 'An article',
            'content' => '<p>Read <a href="/blog/not-a-real-article">this</a> and <a href="https://example.com/outside">that</a>.</p>',
        ]);

        $this->artisan('gadya-cms:check-links')->expectsOutputToContain('lead nowhere')->assertSuccessful();

        $broken = BrokenLink::query()->where('kind', BrokenLink::LINKED)->pluck('url')->all();

        $this->assertContains('/gone-away', $broken);
        $this->assertContains('/blog/not-a-real-article', $broken);
        $this->assertNotContains('/pricing', $broken, 'A link that works is not a problem.');
        $this->assertNotContains('https://example.com/outside', $broken, 'Other people\'s servers are left alone unless asked.');

        $this->assertSame('Page: About us', BrokenLink::query()->where('url', '/gone-away')->value('found_on'));
    }

    public function test_a_forwarded_address_is_not_broken(): void
    {
        Redirect::query()->create(['site_id' => 1, 'from_path' => '/gone-away', 'to_path' => '/about']);

        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['pages']['about']['description'] = 'See <a href="/gone-away">the old one</a>.';
        $this->publishDocument($document);

        $this->artisan('gadya-cms:check-links')->assertSuccessful();

        $this->assertSame(0, BrokenLink::query()->where('kind', BrokenLink::LINKED)->count());
    }

    public function test_the_fix_is_one_click_from_where_the_problem_is(): void
    {
        $this->get('/a-page-that-never-was')->assertNotFound();
        $link = BrokenLink::query()->firstOrFail();

        Livewire::actingAs($this->administrator())
            ->test(ListBrokenLinks::class)
            ->callAction(TestAction::make('redirect')->table($link), ['to_path' => '/about', 'status_code' => 301])
            ->assertNotified('Forwarded');

        $this->assertNotNull($link->fresh()->resolved_at);

        auth()->logout();

        $this->get('/a-page-that-never-was')->assertRedirect('/about');
    }

    public function test_someone_who_writes_in_gets_the_reply_the_client_wrote(): void
    {
        Notification::fake();

        app(AutoReplies::class)->save(['contact' => [
            'enabled' => true,
            'subject' => 'Thanks {{ name }}',
            'body' => "Hello {{ name }},\n\nWe have your note about {{ message }} and will reply today.\n\n{{ business }}",
        ]]);

        $this->post('/cms/forms/contact', ['name' => 'Pat Morgan', 'email' => 'pat@example.com', 'message' => 'Saturday the 14th']);

        Notification::assertSentOnDemand(FormAutoReply::class, fn ($notification, array $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'pat@example.com');

        $submission = FormSubmission::query()->firstOrFail();
        $rendered = app(AutoReplies::class)->render(
            app(AutoReplies::class)->all()['contact']['body'],
            app(AutoReplies::class)->all()['contact']['subject'],
            $submission,
        );

        $this->assertSame('Thanks Pat Morgan', $rendered['subject']);
        $this->assertSame('Hello Pat Morgan,', $rendered['lines'][0]);
        $this->assertStringContainsString('Saturday the 14th', $rendered['lines'][1]);
    }

    public function test_no_reply_goes_out_unless_the_client_turned_it_on(): void
    {
        Notification::fake();

        $this->post('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hello']);

        Notification::assertNotSentTo(new AnonymousNotifiable, FormAutoReply::class);
    }

    public function test_the_words_are_written_in_the_panel(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(Emails::class)
            ->assertSee('Contact')
            ->fillForm(['replies.contact.enabled' => true, 'replies.contact.subject' => 'We have it', 'replies.contact.body' => 'Hello {{ name }}'])
            ->call('save')
            ->assertNotified();

        $reply = app(AutoReplies::class)->for('contact');

        $this->assertSame('We have it', $reply['subject']);
        $this->assertSame('Hello {{ name }}', $reply['body']);
    }
}
