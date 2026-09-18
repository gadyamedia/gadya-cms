<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Events\EventCalendar;
use Gadya\Cms\Filament\Resources\Events\EventResource;
use Gadya\Cms\Filament\Resources\Events\Pages\ListEvents;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class EventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): Event
    {
        return Event::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'title' => 'Summer open day',
            'slug' => 'summer-open-day',
            'summary' => 'Come and try everything.',
            'body' => '<p>Bouncy castles all afternoon.</p>',
            'starts_at' => now()->addWeek()->setTime(10, 0),
            'ends_at' => now()->addWeek()->setTime(16, 0),
            'location' => '12 Evergreen Terrace',
            'price' => 'Free',
            'status' => Event::STATUS_PUBLISHED,
            ...$attributes,
        ]);
    }

    public function test_the_list_shows_what_is_coming_and_keeps_what_has_been(): void
    {
        $this->event();
        $this->event(['title' => 'Last winter', 'slug' => 'last-winter', 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subMonths(2)->addHours(3)]);
        $this->event(['title' => 'Not ready', 'slug' => 'not-ready', 'status' => Event::STATUS_DRAFT]);

        $this->get('/events')
            ->assertOk()
            ->assertSee('Summer open day')
            ->assertSee('Come and try everything.')
            ->assertSee('Already happened')
            ->assertSee('Last winter')
            ->assertDontSee('Not ready');
    }

    public function test_an_event_runs_until_it_ends_not_until_it_starts(): void
    {
        $running = $this->event(['title' => 'Half term camp', 'slug' => 'camp', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

        $this->assertTrue(app(EventCalendar::class)->upcoming()->contains($running), 'A camp in its second day is still on.');
        $this->assertFalse($running->hasFinished());

        $running->update(['ends_at' => now()->subHour()]);

        $this->assertTrue($running->fresh()->hasFinished());
        $this->assertFalse(app(EventCalendar::class)->upcoming()->contains($running));
    }

    public function test_an_event_page_says_when_where_and_how_much_and_describes_itself_to_machines(): void
    {
        $this->event(['booking_url' => 'https://example.com/book']);

        $this->get('/events/summer-open-day')
            ->assertOk()
            ->assertSee('Summer open day')
            ->assertSee('12 Evergreen Terrace')
            ->assertSee('Free')
            ->assertSee('Bouncy castles all afternoon.', false)
            ->assertSee('Book a place')
            ->assertSee('"@type":"Event"', false)
            ->assertSee('"startDate"', false);
    }

    public function test_a_draft_event_is_hidden_from_visitors_and_shown_to_an_editor_previewing(): void
    {
        $this->event(['status' => Event::STATUS_DRAFT]);

        $this->get('/events/summer-open-day')->assertNotFound();

        $this->actingAs($this->editor())
            ->withSession(['gadya-cms.editing' => true])
            ->get('/events/summer-open-day')
            ->assertOk()
            ->assertSee('Summer open day');
    }

    public function test_the_calendar_file_can_be_subscribed_to(): void
    {
        $this->event();
        $this->event(['title' => 'All day fair', 'slug' => 'fair', 'all_day' => true, 'starts_at' => now()->addMonth()->startOfDay(), 'ends_at' => null]);
        $this->event(['title' => 'Long gone', 'slug' => 'gone', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addHours(2)]);

        $ics = $this->get('/events.ics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Summer open day', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:', $ics, 'An all-day event carries a date, not a time.');
        $this->assertStringContainsString('LOCATION:12 Evergreen Terrace', $ics);
        $this->assertStringNotContainsString('Long gone', $ics, 'A calendar is for what is still to come.');
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
    }

    public function test_the_dates_are_written_the_way_a_person_would_say_them(): void
    {
        $sameDay = $this->event(['starts_at' => now()->addWeek()->setTime(10, 0), 'ends_at' => now()->addWeek()->setTime(16, 0)]);
        $allDay = $this->event(['slug' => 'fair', 'all_day' => true, 'starts_at' => now()->addMonth()->startOfDay(), 'ends_at' => null]);

        $this->assertStringContainsString('10:00am to 4:00pm', $sameDay->when());
        $this->assertStringNotContainsString('am', $allDay->when());
        $this->assertStringContainsString($allDay->starts_at->format('l j F Y'), $allDay->when());
    }

    public function test_upcoming_events_are_in_the_sitemap_and_the_panel_counts_them(): void
    {
        $this->event();
        $this->event(['title' => 'Long gone', 'slug' => 'gone', 'starts_at' => now()->subYear(), 'ends_at' => now()->subYear()->addHours(2)]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/events/summer-open-day'), false)
            ->assertDontSee('/events/gone', false);

        $this->assertSame('1', EventResource::getNavigationBadge());

        Livewire::actingAs($this->editor())
            ->test(ListEvents::class)
            ->assertSee('Summer open day');
    }
}
