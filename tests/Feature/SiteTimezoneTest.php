<?php

namespace Gadya\Cms\Tests\Feature;

use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Gadya\Cms\Filament\Pages\SiteDetails;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Support\SiteTimezone;
use Gadya\Cms\Tests\TestCase;
use Livewire\Livewire;

class SiteTimezoneTest extends TestCase
{
    private function zone(?string $name = 'America/New_York'): SiteTimezone
    {
        $zone = app(SiteTimezone::class);

        if ($name !== null) {
            $zone->set($name);
        }

        return $zone;
    }

    public function test_with_nothing_chosen_the_application_zone_is_used_and_nothing_moves(): void
    {
        $zone = app(SiteTimezone::class);

        $this->assertSame('UTC', $zone->name());
        $this->assertFalse($zone->isChosen());
        $this->assertSame('2026-09-30 07:57', $zone->format('2026-09-30 07:57:00', 'Y-m-d H:i'));
    }

    public function test_a_stored_moment_is_shown_in_the_chosen_zone(): void
    {
        $zone = $this->zone();

        $this->assertSame('America/New_York', $zone->name());
        $this->assertSame('30 Sep, 3:57am', $zone->format('2026-09-30 07:57:00', 'j M, g:ia'));
        $this->assertSame('30 Sep, 3:57am', $zone->format(CarbonImmutable::parse('2026-09-30 07:57:00', 'UTC'), 'j M, g:ia'));
        $this->assertNull($zone->local(null));
        $this->assertSame('-', $zone->format(null, 'j M', '-'));
    }

    public function test_a_typed_wall_clock_time_is_stored_as_utc(): void
    {
        $zone = $this->zone();

        $stored = $zone->toStorage('2026-09-30 21:21:00');

        $this->assertSame('2026-10-01 01:21:00', $stored->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $stored->timezone->getName());
        $this->assertSame('2026-10-01 01:21:00', $zone->toStorage('2026-09-30T21:21:00-04:00')->format('Y-m-d H:i:s'), 'An offset in the text is respected.');
        $this->assertNull($zone->toStorage(''));
    }

    public function test_the_local_day_runs_from_local_midnight_in_utc(): void
    {
        $zone = $this->zone();

        $this->assertSame('2026-09-30 04:00:00', $zone->startOfLocalDay('2026-09-30')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 03:59:59', $zone->endOfLocalDay('2026-09-30')->format('Y-m-d H:i:s'));

        $moment = CarbonImmutable::parse('2026-10-01 01:21:00', 'UTC');

        $this->assertSame('2026-09-30 04:00:00', $zone->startOfLocalDay($moment)->format('Y-m-d H:i:s'), '1:21am UTC on the 1st is still the 30th in New York.');
    }

    public function test_the_day_the_clocks_go_forward_is_23_hours_long(): void
    {
        $zone = $this->zone();

        $start = $zone->startOfLocalDay('2026-03-08');
        $end = $zone->endOfLocalDay('2026-03-08');

        $this->assertSame('2026-03-08 05:00:00', $start->format('Y-m-d H:i:s'), 'Still EST at midnight.');
        $this->assertSame('2026-03-09 03:59:59', $end->format('Y-m-d H:i:s'), 'EDT by the end of the day.');
        $this->assertSame(23, (int) round($start->diffInHours($end->addSecond())));
    }

    public function test_the_day_the_clocks_go_back_is_25_hours_long(): void
    {
        $zone = $this->zone();

        $start = $zone->startOfLocalDay('2026-11-01');
        $end = $zone->endOfLocalDay('2026-11-01');

        $this->assertSame('2026-11-01 04:00:00', $start->format('Y-m-d H:i:s'), 'Still EDT at midnight.');
        $this->assertSame('2026-11-02 04:59:59', $end->format('Y-m-d H:i:s'), 'EST by the end of the day.');
        $this->assertSame(25, (int) round($start->diffInHours($end->addSecond())));
    }

    public function test_a_wall_clock_time_either_side_of_a_change_converts_with_the_right_offset(): void
    {
        $zone = $this->zone();

        $this->assertSame('2026-03-07 17:00:00', $zone->toStorage('2026-03-07 12:00:00')->format('Y-m-d H:i:s'), 'Before spring forward: UTC-5.');
        $this->assertSame('2026-03-08 16:00:00', $zone->toStorage('2026-03-08 12:00:00')->format('Y-m-d H:i:s'), 'After spring forward: UTC-4.');
        $this->assertSame('2026-11-01 17:00:00', $zone->toStorage('2026-11-01 12:00:00')->format('Y-m-d H:i:s'), 'After fall back: UTC-5.');
        $this->assertSame('2026-11-01 11:00:00', $zone->format('2026-11-01 16:00:00', 'Y-m-d H:i:s'), 'Back the other way, the same offset.');
        $this->assertSame('2026-03-08 11:30:00', $zone->format('2026-03-08 15:30:00', 'Y-m-d H:i:s'));
    }

    public function test_an_unknown_zone_falls_back_to_the_default(): void
    {
        $options = app(Options::class);
        $options->set(SiteTimezone::OPTION, 'Mars/Olympus_Mons');

        $this->assertSame('UTC', app(SiteTimezone::class)->name());

        config(['gadya-cms.timezone' => 'America/Chicago']);

        $this->assertSame('America/Chicago', app(SiteTimezone::class)->name(), 'The configured zone is next in line.');

        config(['gadya-cms.timezone' => 'Not/AZone']);

        $this->assertSame('UTC', app(SiteTimezone::class)->name());

        app(SiteTimezone::class)->set('Not/AZone');
        $this->assertSame('Mars/Olympus_Mons', $options->get(SiteTimezone::OPTION), 'An invalid zone is not saved over what was there.');
    }

    public function test_the_application_zone_is_never_touched(): void
    {
        $this->zone('Asia/Tokyo');

        Livewire::actingAs($this->administrator())->test(SiteDetails::class)
            ->set('timezoneData.timezone', 'America/Denver')
            ->call('saveTimezone');

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_the_options_offer_common_us_zones_first_then_the_rest_by_region(): void
    {
        $groups = SiteTimezone::groupedOptions();

        $this->assertSame(['America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Phoenix', 'America/Anchorage', 'Pacific/Honolulu'], array_keys(array_values($groups)[0]));
        $this->assertArrayHasKey('Europe/London', $groups['Europe']);
        $this->assertArrayNotHasKey('America/New_York', $groups['America']);
    }

    public function test_the_setting_saves_from_the_locations_page_and_can_be_cleared(): void
    {
        $this->publishDocument();

        Livewire::actingAs($this->editor())->test(SiteDetails::class)
            ->assertSee('Every date and time in your admin, your emails and your reports is shown in this time zone.')
            ->assertSee('Use this device')
            ->set('timezoneData.timezone', 'America/Chicago')
            ->call('saveTimezone')
            ->assertHasNoErrors();

        $this->assertSame('America/Chicago', app(Options::class)->get(SiteTimezone::OPTION));
        $this->assertSame('America/Chicago', app(SiteTimezone::class)->name());

        Livewire::actingAs($this->editor())->test(SiteDetails::class)
            ->assertSet('timezoneData.timezone', 'America/Chicago')
            ->set('timezoneData.timezone', null)
            ->call('saveTimezone');

        $this->assertSame('UTC', app(SiteTimezone::class)->name());
    }

    public function test_the_setting_refuses_something_that_is_not_a_zone(): void
    {
        $this->publishDocument();

        Livewire::actingAs($this->editor())->test(SiteDetails::class)
            ->set('timezoneData.timezone', 'Mars/Olympus_Mons')
            ->call('saveTimezone')
            ->assertHasErrors();

        $this->assertNull(app(Options::class)->get(SiteTimezone::OPTION));
    }

    public function test_someone_without_the_ability_cannot_open_the_page(): void
    {
        $this->actingAs($this->visitor());

        $this->assertFalse(SiteDetails::canAccess());
    }

    public function test_filament_shows_dates_in_the_site_zone_and_converts_back(): void
    {
        $this->assertSame('UTC', FilamentTimezone::get());

        $this->zone('America/New_York');

        $this->assertSame('America/New_York', FilamentTimezone::get());
    }

    public function test_the_portal_is_told_the_zone(): void
    {
        $this->assertSame('UTC', app(PortalSummary::class)->build()['timezone']);

        $this->zone('America/Denver');

        $this->assertSame('America/Denver', app(PortalSummary::class)->build()['timezone']);
    }
}
