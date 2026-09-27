<?php

namespace Gadya\Cms\Tests\Feature;

use Carbon\CarbonImmutable;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\OpeningHoursSettings;
use Gadya\Cms\Hours\BusinessHours;
use Gadya\Cms\Hours\OpeningHours;
use Gadya\Cms\Seo\SeoHead;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * One place for the business's hours, and everything that reads them:
 * the table, the "Open now" badge, Google's structured data and the
 * portal. The hard parts are the late nights, the holidays and the two
 * nights a year the clocks change.
 */
class OpeningHoursTest extends TestCase
{
    /** A bagel shop that also runs a late bar on Fridays and Saturdays. */
    private function hours(array $exceptions = []): OpeningHours
    {
        return OpeningHours::fromArray([
            'timezone' => 'America/New_York',
            'regular' => [
                'mon' => [['07:00', '15:00']],
                'tue' => [['07:00', '15:00']],
                'wed' => [['07:00', '15:00']],
                'thu' => [['07:00', '15:00']],
                'fri' => [['07:00', '15:00'], ['18:00', '02:00']],
                'sat' => [['08:00', '14:00'], ['18:00', '02:00']],
                'sun' => [],
            ],
            'exceptions' => $exceptions,
        ]);
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'America/New_York');
    }

    public function test_open_and_closed_across_a_normal_day(): void
    {
        $hours = $this->hours();

        $this->assertFalse($hours->isOpenAt($this->at('2026-09-28 06:59')), 'Monday before opening.');
        $this->assertTrue($hours->isOpenAt($this->at('2026-09-28 07:00')));
        $this->assertTrue($hours->isOpenAt($this->at('2026-09-28 14:59')));
        $this->assertFalse($hours->isOpenAt($this->at('2026-09-28 15:00')), 'Closing time is closed.');

        $this->assertSame('Open now', $hours->status($this->at('2026-09-28 09:00'))['label']);
        $this->assertSame('closes at 3pm', $hours->status($this->at('2026-09-28 09:00'))['detail']);
        $this->assertTrue($hours->status($this->at('2026-09-28 14:15'))['closing_soon']);
        $this->assertSame('opens at 7am', $hours->status($this->at('2026-09-28 05:00'))['detail']);
        $this->assertSame('opens tomorrow at 7am', $hours->status($this->at('2026-09-28 16:00'))['detail']);
    }

    public function test_the_time_is_the_businesses_not_the_servers(): void
    {
        $hours = $this->hours();

        /* 11:30 UTC on a Monday in September is 7:30am in New Jersey. */
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-09-28 11:30', 'UTC')));
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-09-28 10:30', 'UTC')));
    }

    public function test_a_late_night_runs_past_midnight_into_the_next_day(): void
    {
        $hours = $this->hours();

        $this->assertTrue($hours->isOpenAt($this->at('2026-10-02 23:30')), 'Friday night.');
        $this->assertTrue($hours->isOpenAt($this->at('2026-10-03 01:30')), 'Friday night, after midnight on Saturday.');
        $this->assertFalse($hours->isOpenAt($this->at('2026-10-03 02:00')));
        $this->assertSame('closes at 2am', $hours->status($this->at('2026-10-02 23:30'))['detail']);

        $this->assertTrue($hours->isOpenAt($this->at('2026-10-04 01:00')), 'Saturday night spills into Sunday, a closed day.');
        $this->assertSame('opens tomorrow at 7am', $hours->status($this->at('2026-10-04 10:00'))['detail'], 'Closed Sunday, so next is Monday.');
    }

    public function test_a_holiday_replaces_the_day_and_says_why(): void
    {
        $hours = $this->hours([
            ['date' => '2026-11-26', 'closed' => true, 'label' => 'Thanksgiving'],
            ['date' => '2026-12-31', 'closed' => false, 'ranges' => [['20:00', '01:00']], 'label' => 'New Year’s Eve'],
        ]);

        $this->assertFalse($hours->isOpenAt($this->at('2026-11-26 09:00')), 'Thursday, but Thanksgiving.');
        $this->assertSame('opens tomorrow at 7am', $hours->status($this->at('2026-11-26 09:00'))['detail']);
        $this->assertSame('Thanksgiving', $hours->exceptionOn('2026-11-26')['label']);

        $this->assertFalse($hours->isOpenAt($this->at('2026-12-31 09:00')), 'New Year’s Eve opens only in the evening.');
        $this->assertTrue($hours->isOpenAt($this->at('2027-01-01 00:30')), 'and runs into the new year.');
        $this->assertSame('Closed', OpeningHours::describe($hours->rangesOn('2026-11-26')));
        $this->assertSame('8pm – 1am', OpeningHours::describe($hours->rangesOn('2026-12-31')));
    }

    public function test_the_night_the_clocks_go_forward(): void
    {
        /* Sunday 8 March 2026: 2am jumps to 3am. Saturday's late bar closes at 2am, which does not exist. */
        $hours = $this->hours();

        $this->assertTrue($hours->isOpenAt($this->at('2026-03-08 01:59')));
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-03-08 07:05', 'UTC')), '3:05am EDT, after the jump.');

        /* Monday after the change opens at 7am EDT, which is 11:00 UTC - not 12:00 as in winter. */
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-03-09 11:00', 'UTC')));
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-03-09 10:59', 'UTC')));
    }

    public function test_the_night_the_clocks_go_back(): void
    {
        /* Sunday 1 November 2026: 2am falls back to 1am, so Saturday night lasts an hour longer. */
        $hours = $this->hours();

        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-11-01 05:30', 'UTC')), '1:30am EDT, the first time round.');
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-11-01 06:30', 'UTC')), '1:30am EST, the second time round.');
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-11-01 07:00', 'UTC')), '2am EST: closed.');

        /* Monday after: 7am EST is 12:00 UTC. */
        $this->assertFalse($hours->isOpenAt(CarbonImmutable::parse('2026-11-02 11:30', 'UTC')));
        $this->assertTrue($hours->isOpenAt(CarbonImmutable::parse('2026-11-02 12:00', 'UTC')));
    }

    public function test_open_all_day_every_day_says_so(): void
    {
        $always = array_fill_keys(array_keys(OpeningHours::DAYS), [['00:00', '00:00']]);
        $hours = OpeningHours::fromArray(['timezone' => 'America/New_York', 'regular' => $always]);

        $status = $hours->status($this->at('2026-09-28 03:00'));

        $this->assertTrue($status['open']);
        $this->assertTrue($status['always']);
        $this->assertSame('open 24 hours', $status['detail']);
        $this->assertSame('Open 24 hours', OpeningHours::describe($hours->rangesOn('2026-09-28')));
    }

    public function test_times_read_as_people_say_them(): void
    {
        $this->assertSame('7am', OpeningHours::time('07:00'));
        $this->assertSame('10:30pm', OpeningHours::time('22:30'));
        $this->assertSame('noon', OpeningHours::time('12:00'));
        $this->assertSame('midnight', OpeningHours::time('00:00'));
        $this->assertSame('7am – 3pm, 6pm – 2am', OpeningHours::describe($this->hours()->regular()['fri']));
    }

    public function test_nonsense_is_dropped_rather_than_guessed_at(): void
    {
        $hours = OpeningHours::fromArray([
            'timezone' => 'Mars/Olympus_Mons',
            'regular' => ['mon' => [['25:00', '17:00'], ['09:00:00', '17:00:00'], ['x', 'y']]],
            'exceptions' => [['date' => 'soon', 'closed' => true], ['date' => '2026-12-25', 'closed' => false, 'ranges' => []]],
        ]);

        $this->assertSame('America/New_York', $hours->timezone(), 'An unknown zone falls back to the configured one.');
        $this->assertSame([['09:00', '17:00']], $hours->regular()['mon']);
        $this->assertSame([], $hours->regular()['tue']);
        $this->assertCount(1, $hours->exceptions());
        $this->assertTrue($hours->exceptions()[0]['closed'], 'A special day with no times is a closed day.');
    }

    public function test_the_schema_org_specification_groups_days_and_lists_the_holidays_to_come(): void
    {
        $hours = $this->hours([
            ['date' => '2026-01-01', 'closed' => true, 'label' => 'Long gone'],
            ['date' => '2026-11-26', 'closed' => true, 'label' => 'Thanksgiving'],
            ['date' => '2026-12-24', 'closed' => false, 'ranges' => [['07:00', '12:00']], 'label' => 'Christmas Eve'],
        ]);

        $specification = $hours->specification($this->at('2026-09-28 09:00'));

        $this->assertSame([
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            'opens' => '07:00',
            'closes' => '15:00',
        ], $specification[0]);
        $this->assertContains(['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Friday', 'Saturday'], 'opens' => '18:00', 'closes' => '02:00'], $specification);
        $this->assertContains(['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => '2026-11-26', 'validThrough' => '2026-11-26'], $specification);
        $this->assertContains(['@type' => 'OpeningHoursSpecification', 'opens' => '07:00', 'closes' => '12:00', 'validFrom' => '2026-12-24', 'validThrough' => '2026-12-24'], $specification);
        $this->assertNotContains('2026-01-01', array_column($specification, 'validFrom'), 'A holiday that has passed is not told.');
    }

    public function test_the_shape_a_google_business_profile_sync_would_send(): void
    {
        $hours = $this->hours([['date' => '2026-11-26', 'closed' => true, 'label' => 'Thanksgiving']]);

        $profile = $hours->toGoogleBusinessProfile($this->at('2026-09-28 09:00'));

        $this->assertContains([
            'openDay' => 'FRIDAY', 'openTime' => ['hours' => 18, 'minutes' => 0],
            'closeDay' => 'SATURDAY', 'closeTime' => ['hours' => 2, 'minutes' => 0],
        ], $profile['regularHours']['periods']);
        $this->assertSame(['startDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'endDate' => ['year' => 2026, 'month' => 11, 'day' => 26], 'closed' => true], $profile['specialHours']['specialHourPeriods'][0]);
    }

    public function test_hours_are_set_in_the_admin_published_and_shown_on_the_site(): void
    {
        $this->publishDocument();

        Livewire::actingAs($this->editor())
            ->test(OpeningHoursSettings::class)
            ->fillForm([
                'timezone' => 'America/New_York',
                'regular' => [
                    'mon' => [['open' => '07:00', 'close' => '15:00']],
                    'sat' => [['open' => '18:00', 'close' => '02:00']],
                ],
                'exceptions' => [
                    ['date' => now()->addDays(10)->toDateString(), 'label' => 'Staff party', 'closed' => true, 'ranges' => []],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = app(SiteContentRepository::class)->draft()['hours'];

        $this->assertSame([['07:00', '15:00']], $draft['regular']['mon']);
        $this->assertSame([['18:00', '02:00']], $draft['regular']['sat']);
        $this->assertSame('Staff party', $draft['exceptions'][0]['label']);
        $this->assertFalse(app(BusinessHours::class)->published()->isSet(), 'Nothing is live before Publish.');

        app(PublishSiteContent::class)->handle();
        app(SiteContentRepository::class)->flushPublishedCache();

        $table = Blade::render('<x-gadya-cms::opening-hours />');

        $this->assertStringContainsString('<caption class="cms-hours__caption">Opening hours</caption>', $table);
        $this->assertStringContainsString('<th scope="row" class="cms-hours__name">Monday</th>', $table);
        $this->assertStringContainsString('<time datetime="07:00">7am</time> – <time datetime="15:00">3pm</time>', $table);
        $this->assertStringContainsString('Holiday hours', $table);
        $this->assertStringContainsString('(Staff party)', $table);
        $this->assertStringContainsString('aria-current="date"', $table);

        $this->assertStringContainsString('cms-open-status', Blade::render('<x-gadya-cms::open-status />'));
        $this->assertStringContainsString('cms-todays-hours__label">Today<', Blade::render('<x-gadya-cms::todays-hours />'));

        $jsonLd = json_encode(app(SeoHead::class)->structuredData(['title' => 'Home']));
        $this->assertStringContainsString('"openingHoursSpecification"', $jsonLd);
        $this->assertStringContainsString('"validThrough":"'.now()->addDays(10)->toDateString().'"', $jsonLd);

        $this->assertSame([['07:00', '15:00']], OpeningHoursSettings::fromFormState(OpeningHoursSettings::toFormState(app(BusinessHours::class)->published()))->regular()['mon'], 'The screen reopens on what was saved.');
    }

    public function test_the_portal_is_told_the_hours_or_nothing_at_all(): void
    {
        $this->publishDocument();

        $this->assertArrayNotHasKey('hours', app(PortalSummary::class)->build(), 'No hours set: the section is left out.');

        $this->assertSame('', trim(Blade::render('<x-gadya-cms::opening-hours />')), 'Nor is an empty table drawn.');
        $this->assertStringNotContainsString('openingHoursSpecification', json_encode(app(SeoHead::class)->structuredData(['title' => 'Home'])));

        $repository = app(SiteContentRepository::class);
        $repository->saveDraft([...$repository->draft(), 'hours' => $this->hours([['date' => now()->addDay()->toDateString(), 'closed' => true, 'label' => 'Inventory']])->toArray()]);
        app(PublishSiteContent::class)->handle();
        $repository->flushPublishedCache();

        $summary = app(PortalSummary::class)->build()['hours'];

        $this->assertSame('America/New_York', $summary['timezone']);
        $this->assertSame([['07:00', '15:00'], ['18:00', '02:00']], $summary['regular']['fri']);
        $this->assertSame([], $summary['regular']['sun']);
        $this->assertSame(['date' => now()->addDay()->toDateString(), 'closed' => true, 'ranges' => [], 'label' => 'Inventory'], $summary['exceptions'][0]);
        $this->assertIsBool($summary['open_now']);
    }
}
