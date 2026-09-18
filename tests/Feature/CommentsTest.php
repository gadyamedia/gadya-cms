<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Filament\Resources\Comments\CommentResource;
use Gadya\Cms\Filament\Resources\Comments\Pages\ListComments;
use Gadya\Cms\Models\Comment;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Notifications\CommentPosted;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class CommentsTest extends TestCase
{
    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        config(['gadya-cms.blog.comments.enabled' => true, 'gadya-cms.blog.comments.notify' => ['owner@example.com']]);
        Notification::fake();

        $this->post = Post::factory()->published()->create(['slug' => 'foam-parties', 'title' => 'Foam parties']);
    }

    private function comment(array $attributes = []): Comment
    {
        return Comment::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'post_id' => $this->post->getKey(),
            'author_name' => 'Pat',
            'body' => 'We loved it.',
            ...$attributes,
        ]);
    }

    public function test_a_comment_waits_to_be_approved_and_the_owner_is_told(): void
    {
        $this->post('/blog/foam-parties/comments', ['author_name' => 'Pat', 'author_email' => 'pat@example.com', 'body' => 'We loved it.'])
            ->assertRedirect('/blog/foam-parties#comments')
            ->assertSessionHas('gadya-cms.comment');

        $comment = Comment::query()->firstOrFail();

        $this->assertSame(Comment::PENDING, $comment->status);
        $this->assertSame($this->post->getKey(), $comment->post_id);
        $this->assertNotNull($comment->visitor_hash);

        Notification::assertSentOnDemand(CommentPosted::class, fn (CommentPosted $notification, array $channels, $notifiable): bool => in_array('owner@example.com', $notifiable->routes['mail'] ?? [], true)
            && $notification->comment->is($comment));

        $this->get('/blog/foam-parties')->assertOk()->assertDontSee('We loved it.');
    }

    public function test_an_approved_comment_appears_under_the_article(): void
    {
        $waiting = $this->comment(['body' => 'Still waiting.']);
        $showing = $this->comment(['author_name' => 'Sam', 'body' => 'Brilliant afternoon.', 'status' => Comment::APPROVED]);

        $this->get('/blog/foam-parties')
            ->assertOk()
            ->assertSee('Brilliant afternoon.')
            ->assertSee('Sam')
            ->assertDontSee('Still waiting.')
            ->assertSee('1 comment');
    }

    public function test_a_bot_and_a_bad_comment_get_nowhere(): void
    {
        $this->post('/blog/foam-parties/comments', ['author_name' => 'Bot', 'body' => 'Buy things', 'website' => 'https://spam.example'])
            ->assertRedirect();

        $this->from('/blog/foam-parties')
            ->post('/blog/foam-parties/comments', ['author_name' => '', 'body' => ''])
            ->assertSessionHasErrors(['author_name', 'body']);

        $this->assertDatabaseCount('gadyacms_comments', 0);
        Notification::assertNothingSent();
    }

    public function test_comments_are_refused_when_the_site_does_not_want_them_or_the_article_is_not_live(): void
    {
        config(['gadya-cms.blog.comments.enabled' => false]);

        $this->post('/blog/foam-parties/comments', ['author_name' => 'Pat', 'body' => 'Hello'])->assertNotFound();

        config(['gadya-cms.blog.comments.enabled' => true]);
        $draft = Post::factory()->create(['slug' => 'not-published']);

        $this->post('/blog/not-published/comments', ['author_name' => 'Pat', 'body' => 'Hello'])->assertNotFound();
    }

    public function test_a_site_that_trusts_its_readers_can_skip_the_queue(): void
    {
        config(['gadya-cms.blog.comments.moderate' => false]);

        $this->post('/blog/foam-parties/comments', ['author_name' => 'Pat', 'body' => 'Straight up.']);

        $this->assertSame(Comment::APPROVED, Comment::query()->firstOrFail()->status);
        $this->get('/blog/foam-parties')->assertSee('Straight up.');
    }

    public function test_the_panel_counts_what_is_waiting_and_lets_it_through_or_throws_it_away(): void
    {
        $waiting = $this->comment();
        $spam = $this->comment(['author_name' => 'Bot', 'body' => 'Buy things']);

        $this->actingAs($this->editor());
        $this->assertSame('2', CommentResource::getNavigationBadge());

        Livewire::actingAs($this->editor())
            ->test(ListComments::class)
            ->assertCanSeeTableRecords([$waiting, $spam])
            ->callAction(TestAction::make('approve')->table($waiting))
            ->callAction(TestAction::make('spam')->table($spam));

        $this->assertSame(Comment::APPROVED, $waiting->fresh()->status);
        $this->assertSame(Comment::SPAM, $spam->fresh()->status);
        $this->assertNull(CommentResource::getNavigationBadge(), 'Nothing waiting, no badge.');
        $this->assertNotNull($waiting->fresh()->approved_by);
    }

    public function test_only_someone_who_may_write_articles_sees_the_comments(): void
    {
        $this->actingAs($this->visitor())->get(CommentResource::getUrl())->assertForbidden();
    }
}
