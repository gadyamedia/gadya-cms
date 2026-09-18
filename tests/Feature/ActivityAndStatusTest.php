<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Activity\Activity;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\SiteStatus;
use Gadya\Cms\Filament\Resources\Activity\ActivityResource;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Models\AuditLog;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Services\SchedulePublish;
use Gadya\Cms\Support\Maintenance;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class ActivityAndStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        AuditLog::query()->delete();
    }

    public function test_every_change_is_noted_with_who_made_it(): void
    {
        $editor = $this->editor();
        $this->actingAs($editor);

        $post = Post::factory()->create(['title' => 'A new article']);
        $post->update(['title' => 'A renamed article']);
        $post->delete();

        $entries = AuditLog::query()->orderBy('id')->get();

        $this->assertSame(['post.created', 'post.updated', 'post.trashed'], $entries->pluck('event')->all());
        $this->assertSame($editor->getKey(), $entries->first()->user_id);
        $this->assertSame('A renamed article', $entries->last()->subject);
        $this->assertSame(['title' => 'A renamed article'], $entries[1]->after, 'The note says what changed, not the whole row.');
    }

    public function test_publishing_is_noted_and_reads_plainly(): void
    {
        $this->actingAs($this->editor());

        app(PublishSiteContent::class)->handle(auth()->user(), 'Summer update');

        $entry = AuditLog::query()->where('event', 'site.published')->firstOrFail();

        $this->assertSame('Summer update', $entry->subject);
        $this->assertSame('Published the site', Activity::describe('site.published'));
        $this->assertSame('Trashed page', Activity::describe('page.trashed'));
    }

    public function test_the_log_is_there_for_whoever_looks_after_the_site(): void
    {
        $this->actingAs($this->administrator());
        Post::factory()->create(['title' => 'Something']);

        $this->get(ActivityResource::getUrl())->assertOk()->assertSee('Created post');
    }

    public function test_an_editor_is_not_shown_the_log(): void
    {
        $this->actingAs($this->editor())->get(ActivityResource::getUrl())->assertForbidden();
    }

    public function test_the_log_can_be_turned_off_entirely(): void
    {
        config(['gadya-cms.activity.enabled' => false]);

        app(Activity::class)->record('site.published', 'Nothing doing');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_old_entries_are_pruned(): void
    {
        app(Activity::class)->record('site.published', 'Old');
        AuditLog::query()->update(['created_at' => now()->subYear()]);
        app(Activity::class)->record('site.published', 'Recent');

        $this->artisan('gadya-cms:prune-activity')->assertSuccessful();

        $this->assertSame(['Recent'], AuditLog::query()->pluck('subject')->all());
    }

    public function test_a_publish_can_be_held_until_a_time_and_then_goes_out(): void
    {
        $repository = app(SiteContentRepository::class);
        $document = $repository->draft();
        $document['announcement'] = 'Closed for the holidays';
        $repository->saveDraft($document);

        $page = Page::query()->where('slug', 'about')->firstOrFail();

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callAction('publishChanges', ['at' => now()->addDays(2)->format('Y-m-d H:i:s')]);

        $this->assertTrue(app(SchedulePublish::class)->isPending());
        $this->assertSame('Now booking summer parties', $repository->published()['announcement'], 'Nothing goes out early.');

        $this->artisan('gadya-cms:publish-due')->expectsOutputToContain('not yet')->assertSuccessful();

        $this->travel(3)->days();

        $this->artisan('gadya-cms:publish-due')->expectsOutputToContain('live as of now')->assertSuccessful();

        $this->assertSame('Closed for the holidays', $repository->published()['announcement']);
        $this->assertFalse(app(SchedulePublish::class)->isPending(), 'A publish that happened is no longer pending.');
    }

    public function test_publishing_now_cancels_anything_that_was_waiting(): void
    {
        app(SchedulePublish::class)->schedule(now()->addWeek());

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callAction('publishChanges', ['at' => null])
            ->assertNotified('Your changes are now live');

        $this->assertFalse(app(SchedulePublish::class)->isPending());
    }

    public function test_coming_soon_closes_the_site_to_visitors_but_not_to_the_people_working_on_it(): void
    {
        Route::middleware('web')->get('/about', fn (): string => 'the real page');

        $this->get('/about')->assertOk()->assertSee('the real page');

        Livewire::actingAs($this->administrator())
            ->test(SiteStatus::class)
            ->fillForm(['enabled' => true, 'heading' => 'Back on Monday', 'message' => 'We are painting.', 'password' => 'letmein'])
            ->call('save')
            ->assertNotified();

        /* The screen signed somebody in; a visitor has not. */
        auth()->logout();

        $this->get('/about')
            ->assertStatus(503)
            ->assertSee('Back on Monday')
            ->assertSee('We are painting.')
            ->assertDontSee('the real page');
    }

    public function test_someone_who_may_work_on_the_site_still_sees_it(): void
    {
        Route::middleware('web')->get('/about', fn (): string => 'the real page');
        app(Maintenance::class)->save(['enabled' => true, 'heading' => 'Back soon', 'message' => 'Shortly.']);

        $this->get('/about')->assertStatus(503);

        $this->actingAs($this->editor())->get('/about')->assertOk()->assertSee('the real page');
    }

    public function test_the_panel_and_the_editor_stay_open_while_the_site_is_closed(): void
    {
        app(Maintenance::class)->save(['enabled' => true, 'heading' => 'Back soon', 'message' => 'Shortly.']);

        $this->get('/admin/login')->assertOk();
        $this->get('/sitemap.xml')->assertStatus(503);
    }

    public function test_a_password_lets_someone_in_without_an_account(): void
    {
        app(Maintenance::class)->save(['enabled' => true, 'heading' => 'Back soon', 'message' => 'Shortly.', 'password' => 'letmein']);
        Route::middleware('web')->get('/about', fn (): string => 'the real page');

        $this->get('/about')->assertStatus(503);
        $this->get('/about?pass=wrong')->assertStatus(503);

        $this->get('/about?pass=letmein')
            ->assertOk()
            ->assertSee('the real page')
            ->assertCookie(Maintenance::COOKIE, 'letmein', encrypted: false);

        $this->withUnencryptedCookie(Maintenance::COOKIE, 'letmein')->get('/about')->assertOk();
    }

    public function test_it_opens_itself_when_the_time_it_named_has_passed(): void
    {
        app(Maintenance::class)->save(['enabled' => true, 'heading' => 'Back soon', 'message' => 'Shortly.', 'until' => now()->addHour()->toIso8601String()]);

        $this->assertTrue(app(Maintenance::class)->isOn());

        $this->travel(2)->hours();

        $this->assertFalse(app(Maintenance::class)->isOn(), 'The site opens itself rather than waiting for somebody to remember.');
    }
}
