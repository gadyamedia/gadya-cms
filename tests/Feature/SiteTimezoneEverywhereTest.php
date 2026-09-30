<?php

namespace Gadya\Cms\Tests\Feature;

use Carbon\CarbonImmutable;
use Gadya\Cms\Analytics\AnalyticsReport;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Events\EventCalendar;
use Gadya\Cms\Filament\Pages\OpeningHoursSettings;
use Gadya\Cms\Filament\Resources\Activity\Pages\ListActivity;
use Gadya\Cms\Filament\Resources\Events\Pages\CreateEvent;
use Gadya\Cms\Filament\Resources\Events\Pages\EditEvent;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Filament\Resources\Posts\Pages\CreatePost;
use Gadya\Cms\Filament\Resources\Posts\Pages\ListPosts;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Hours\OpeningHours;
use Gadya\Cms\Livewire\SenderPanelView;
use Gadya\Cms\Mail\PortalMail;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Models\AuditLog;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Services\SchedulePublish;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Support\SiteTimezone;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * The site's time zone, through everything that draws or decides by the
 * clock. Every test names a moment in UTC and says what the business
 * would call it.
 */
class SiteTimezoneEverywhereTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function useZone(string $zone = 'America/New_York'): void
    {
        app(SiteTimezone::class)->set($zone);
    }

    private function visitAt(string $utc): void
    {
        PageView::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'path' => '/',
            'visitor_hash' => str_repeat('a', 64),
            'viewed_at' => CarbonImmutable::parse($utc, 'UTC'),
        ]);
    }

    public function test_a_filament_table_shows_a_utc_timestamp_as_the_local_time(): void
    {
        $post = Post::factory()->published()->create(['title' => 'Out there', 'published_at' => '2026-09-30 07:57:00']);

        Livewire::actingAs($this->editor())->test(ListPosts::class)
            ->assertCanSeeTableRecords([$post])
            ->assertSee('Sep 30, 2026 07:57:00');

        $this->useZone();

        Livewire::actingAs($this->editor())->test(ListPosts::class)
            ->assertSee('Sep 30, 2026 03:57:00')
            ->assertDontSee('07:57:00');

        $this->assertSame('2026-09-30 07:57:00', $post->fresh()->published_at->format('Y-m-d H:i:s'), 'What is stored does not move.');
    }

    public function test_the_activity_log_tooltip_is_local_too(): void
    {
        $this->useZone();
        $log = new AuditLog(['site_id' => app(SiteContext::class)->id(), 'event' => 'page.updated', 'subject' => 'Home']);
        $log->created_at = '2026-09-30 07:57:00';
        $log->save();

        Livewire::actingAs($this->administrator())->test(ListActivity::class)
            ->assertSee('Wed 30 Sep 2026, 3:57am');
    }

    public function test_a_scheduled_article_converts_the_local_time_typed_to_utc_and_back(): void
    {
        $this->useZone();

        Livewire::actingAs($this->editor())->test(CreatePost::class)
            ->fillForm([
                'title' => 'Coming soon',
                'slug' => 'coming-soon',
                'content' => '<p>Words.</p>',
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => '2026-10-05 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $post = Post::query()->where('slug', 'coming-soon')->firstOrFail();

        $this->assertSame('2026-10-05 13:00:00', $post->published_at->format('Y-m-d H:i:s'), '9am in New York is 13:00 UTC, in October.');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:59:00', 'UTC'));
        $this->assertTrue($post->fresh()->isScheduled(), '8:59am there: not yet.');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 13:01:00', 'UTC'));
        $this->assertTrue($post->fresh()->isLive(), '9:01am there: live.');

        $this->get('/blog/coming-soon')->assertOk()->assertSee('October 5, 2026');

        Livewire::actingAs($this->editor())->test(ListPosts::class)->assertSee('Oct 5, 2026 09:00:00');
    }

    public function test_a_publish_held_until_a_local_time_goes_out_at_that_time(): void
    {
        $this->useZone();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));

        Livewire::actingAs($this->editor())
            ->test(ListPages::class)
            ->callAction('publishChanges', ['at' => '2026-10-05 09:00:00']);

        $schedule = app(SchedulePublish::class);

        $this->assertSame('2026-10-05 13:00:00', $schedule->at()->utc()->format('Y-m-d H:i:s'), '9am in New York.');
        $this->assertSame('Monday 5 October, 9:00am', app(SiteTimezone::class)->format($schedule->at(), 'l j F, g:ia'));

        $this->travelTo(CarbonImmutable::parse('2026-10-05 12:59:00', 'UTC'));
        $this->artisan('gadya-cms:publish-due')->expectsOutputToContain('Mon 5 Oct 2026, 9:00am; not yet')->assertSuccessful();
    }

    public function test_an_event_form_keeps_the_clock_time_typed(): void
    {
        $this->useZone();

        Livewire::actingAs($this->editor())->test(CreateEvent::class)
            ->fillForm([
                'title' => 'Open evening',
                'slug' => 'open-evening',
                'starts_at' => '2026-10-03 18:00:00',
                'ends_at' => '2026-10-03 20:00:00',
                'status' => Event::STATUS_PUBLISHED,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = Event::query()->firstOrFail();

        $this->assertSame('2026-10-03 18:00:00', $event->starts_at->format('Y-m-d H:i:s'), '6pm is stored as 6pm, not converted.');

        Livewire::actingAs($this->editor())->test(EditEvent::class, ['record' => $event->getKey()])
            ->assertSet('data.starts_at', '2026-10-03 18:00');
    }

    public function test_the_public_article_date_is_the_local_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC'));
        Post::factory()->published()->create(['slug' => 'late-one', 'published_at' => '2026-10-01 01:30:00']);

        $this->get('/blog/late-one')->assertSee('October 1, 2026');

        $this->useZone();

        $this->get('/blog/late-one')->assertSee('September 30, 2026')->assertDontSee('October 1, 2026');
    }

    public function test_the_staff_email_gives_the_time_the_enquiry_was_made_locally(): void
    {
        $submission = FormSubmission::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'form' => 'contact',
            'data' => ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hello there'],
            'path' => '/contact',
            'created_at' => '2026-09-30 01:21:00',
        ]);
        $line = fn (): string => implode("\n", (new FormSubmitted($submission, FormDefinition::find('contact')))->toMail(new \stdClass)->introLines);

        $this->assertStringContainsString('Sent from /contact on Wed 30 Sep 2026, 1:21am.', $line());

        $this->useZone();

        $this->assertStringContainsString('Sent from /contact on Tue 29 Sep 2026, 9:21pm.', $line());
    }

    public function test_today_is_the_businesss_today_not_utcs(): void
    {
        $this->useZone();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 01:30:00', 'UTC'));

        $this->visitAt('2026-10-01 00:30:00');
        $this->visitAt('2026-09-30 05:00:00');
        $this->visitAt('2026-09-30 03:00:00');

        $today = new AnalyticsReport(0);

        $this->assertSame('2026-09-30 04:00:00', $today->since()->format('Y-m-d H:i:s'), 'Local midnight, in UTC.');
        $this->assertSame(2, $today->headline()['views'], 'The 3am UTC visit was yesterday evening there.');

        app(SiteTimezone::class)->set(null);

        $this->assertSame(1, (new AnalyticsReport(0))->headline()['views'], 'With no zone chosen it is UTC today, as before.');
    }

    public function test_the_daily_chart_groups_by_local_day_across_a_clock_change(): void
    {
        $this->useZone();
        $this->travelTo(CarbonImmutable::parse('2026-03-09 12:00:00', 'UTC'));

        /* Mar 7 11:30pm EST, Mar 8 12:30am EST, Mar 8 11:30pm EDT, Mar 9 12:30am EDT. */
        foreach (['2026-03-08 04:30:00', '2026-03-08 05:30:00', '2026-03-09 03:30:00', '2026-03-09 04:30:00'] as $moment) {
            $this->visitAt($moment);
        }

        $days = collect((new AnalyticsReport(3))->daily())->pluck('views', 'day')->all();

        $this->assertSame(['Mar 7' => 1, 'Mar 8' => 2, 'Mar 9' => 1], $days);
    }

    public function test_the_daily_chart_groups_by_local_day_across_the_fall_back(): void
    {
        $this->useZone();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));

        /* Oct 31 11:30pm EDT, Nov 1 12:30am EDT, Nov 1 11:30pm EST, Nov 2 12:30am EST. */
        foreach (['2026-11-01 03:30:00', '2026-11-01 04:30:00', '2026-11-02 04:30:00', '2026-11-02 05:30:00'] as $moment) {
            $this->visitAt($moment);
        }

        $days = collect((new AnalyticsReport(3))->daily())->pluck('views', 'day')->all();

        $this->assertSame(['Oct 31' => 1, 'Nov 1' => 2, 'Nov 2' => 1], $days);
    }

    public function test_an_events_clock_times_never_shift_when_the_zone_changes(): void
    {
        $event = Event::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'title' => 'Open evening',
            'slug' => 'open-evening',
            'starts_at' => '2026-10-03 18:00:00',
            'ends_at' => '2026-10-03 20:00:00',
            'status' => Event::STATUS_PUBLISHED,
        ]);

        $this->assertSame('Saturday 3 October 2026, 6:00pm to 8:00pm', $event->when());

        $this->useZone();

        $this->assertSame('Saturday 3 October 2026, 6:00pm to 8:00pm', $event->fresh()->when(), 'The 6pm the business typed is still 6pm.');
        $this->get('/events/open-evening')->assertSee('6:00pm to 8:00pm');

        $ics = app(EventCalendar::class)->ics();
        $this->assertStringContainsString('DTSTART:20261003T220000Z', $ics, '6pm in New York in October is 22:00 UTC.');
        $this->assertStringContainsString('DTEND:20261004T000000Z', $ics);
        $this->assertSame('2026-10-03T18:00:00-04:00', app(EventCalendar::class)->structuredData($event)['startDate']);
    }

    public function test_an_event_is_judged_by_the_businesss_clock(): void
    {
        Event::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'title' => 'Late show',
            'slug' => 'late-show',
            'starts_at' => '2026-09-30 20:00:00',
            'ends_at' => '2026-09-30 23:00:00',
            'status' => Event::STATUS_PUBLISHED,
        ]);

        /* 1:30am UTC on the 1st: 9:30pm on the 30th in New York, mid-show. */
        $this->travelTo(CarbonImmutable::parse('2026-10-01 01:30:00', 'UTC'));

        $this->assertCount(0, app(EventCalendar::class)->upcoming(), 'On UTC clocks it is over.');

        $this->useZone();

        $this->assertCount(1, app(EventCalendar::class)->upcoming());
        $this->assertFalse(Event::query()->firstOrFail()->hasFinished());
    }

    public function test_hours_follow_the_site_zone_unless_they_keep_their_own(): void
    {
        config(['gadya-cms.hours.timezone' => 'America/New_York']);
        $regular = ['mon' => [['07:00', '15:00']]];

        $this->assertSame('America/New_York', OpeningHours::fromArray(['regular' => $regular])->timezone(), 'Nothing chosen: the configured default, as before.');

        $this->useZone('America/Chicago');

        $followed = OpeningHours::fromArray(['regular' => $regular]);
        $this->assertSame('America/Chicago', $followed->timezone());
        $this->assertNull($followed->ownTimezone());
        $this->assertNull($followed->toArray()['timezone'], 'Saved as "follow the site", so a later change of zone moves it.');

        $own = OpeningHours::fromArray(['timezone' => 'America/Los_Angeles', 'regular' => $regular]);
        $this->assertSame('America/Los_Angeles', $own->timezone());
        $this->assertSame('America/Los_Angeles', $own->toArray()['timezone']);

        $this->travelTo(CarbonImmutable::parse('2026-09-28 12:30:00', 'UTC'));
        $this->assertTrue($followed->isOpenAt(), 'Monday 7:30am in Chicago.');
        $this->assertFalse($own->isOpenAt(), 'Monday 5:30am in Los Angeles.');
    }

    public function test_the_opening_hours_screen_keeps_its_clock_times_whatever_the_zone(): void
    {
        $this->useZone();
        $this->publishDocument([...app(SiteContentRepository::class)->defaults(), 'hours' => ['timezone' => null, 'regular' => ['mon' => [['07:00', '15:00']]], 'exceptions' => []]]);
        app(SiteContentRepository::class)->saveDraft([...app(SiteContentRepository::class)->draft(), 'hours' => ['regular' => ['mon' => [['07:00', '15:00']]]]]);

        $screen = Livewire::actingAs($this->editor())->test(OpeningHoursSettings::class);

        $monday = array_values($screen->get('data.regular.mon'))[0];
        $this->assertSame(['07:00', '15:00'], [$monday['open'], $monday['close']], 'The times are a wall clock, not moments to convert.');

        $screen->call('save');

        $this->assertSame([['07:00', '15:00']], app(SiteContentRepository::class)->draft()['hours']['regular']['mon']);
    }

    public function test_the_audit_asks_for_a_zone_but_does_not_insist(): void
    {
        $check = fn (): array => collect(app(InstallAudit::class)->checks())->firstWhere('label', "The site's time zone is set");

        $this->assertSame(InstallAudit::OPTIONAL, $check()['status']);
        $this->assertStringContainsString('Set the Time zone under', $check()['fix']);

        $this->useZone();

        $this->assertSame(InstallAudit::OK, $check()['status']);
    }

    private function pair(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh', 'site_name' => 'Acme Dental']);
        config(['gadya-cms.mail.shared' => true, 'gadya-cms.seo.site_name' => 'Acme Dental']);
        app()->forgetInstance(SharedSender::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function portalStatus(string $subject, string $sentAt = '2026-09-30T07:57:00Z'): array
    {
        return [
            'address' => 'acme@on.gadya.media',
            'enabled' => true,
            'sent_this_hour' => 1,
            'per_hour' => 100,
            'recent' => [['sent_at' => $sentAt, 'to' => 'owner@acme.test', 'recipients' => 1, 'subject' => $subject, 'failed' => false]],
        ];
    }

    public function test_the_sender_panel_shows_local_times_and_refreshes_without_waiting(): void
    {
        $this->pair();
        $this->useZone();
        Http::fake(['portal.test/*' => Http::sequence()
            ->push($this->portalStatus('First'))
            ->push($this->portalStatus('Second'))
            ->push($this->portalStatus('Third'))]);

        $panel = Livewire::actingAs($this->administrator())->test(SenderPanelView::class, ['limit' => 5])
            ->assertSee('First')
            ->assertSee('30 Sep, 3:57am')
            ->assertSee('Refresh');

        Http::assertSentCount(1);

        $panel->call('$refresh')->assertSee('First')->assertDontSee('Second');
        Http::assertSentCount(1);

        $panel->call('refresh')->assertSee('Second')->assertDontSee('First');
        Http::assertSentCount(2);

        $panel->dispatch(SenderPanelView::SENT)->assertSee('Third');
        Http::assertSentCount(3);
    }

    public function test_the_portal_answer_is_kept_for_sixty_seconds_and_no_longer(): void
    {
        $this->pair();
        Http::fake(['portal.test/*' => Http::response($this->portalStatus('Hello'))]);

        $mail = app(PortalMail::class);

        $mail->status();
        $this->travel(50)->seconds();
        $mail->status();
        Http::assertSentCount(1);

        $this->travel(11)->seconds();
        $mail->status();
        Http::assertSentCount(2);

        $mail->status(fresh: true);
        Http::assertSentCount(3);
    }

    public function test_sending_never_waits_on_the_portal_for_its_address(): void
    {
        $this->pair();
        Http::fake();

        $this->assertNull(app(PortalMail::class)->address());

        Http::assertNothingSent();
    }
}
