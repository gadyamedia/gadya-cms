<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Pages\Dashboard;
use Gadya\Cms\Filament\Pages\GetFound;
use Gadya\Cms\Filament\Pages\SearchSettings;
use Gadya\Cms\Models\SearchSnapshot;
use Gadya\Cms\Search\CountryCodes;
use Gadya\Cms\Search\PortalSearchConsole;
use Gadya\Cms\Search\SearchConsole;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

class PortalSearchConsoleTest extends TestCase
{
    private const BASE = 'https://portal.test/api/connect/v1/search-console';

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Cache::flush();
    }

    private function pair(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
    }

    private function onHttps(): void
    {
        URL::forceScheme('https');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function searchStatusBody(string $status = 'connected', array $overrides = []): array
    {
        return array_merge([
            'available' => true,
            'status' => $status,
            'source' => $status === 'not_connected' ? null : 'client',
            'google_email' => $status === 'not_connected' ? null : 'owner@example.com',
            'property' => $status === 'not_connected' ? null : 'sc-domain:example.com',
            'connected_at' => '2026-09-01T10:00:00Z',
            'last_synced_at' => '2026-09-29T06:30:00Z',
            'last_error' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function performance(array $overrides = []): array
    {
        return ['data' => array_merge([
            'property' => 'sc-domain:example.com',
            'from' => '2026-09-03',
            'to' => '2026-09-09',
            'synced_at' => '2026-09-12T06:30:00Z',
            'totals' => ['clicks' => 120, 'impressions' => 4000, 'ctr' => 0.03, 'position' => 8.44],
            'previous' => ['clicks' => 100, 'impressions' => 5000, 'ctr' => 0.02, 'position' => 9.0],
            'daily' => [
                ['date' => '2026-09-03', 'clicks' => 10, 'impressions' => 400, 'ctr' => 0.025, 'position' => 8.0],
                ['date' => '2026-09-05', 'clicks' => 30, 'impressions' => 900, 'ctr' => 0.033, 'position' => 7.0],
            ],
            'queries' => [['query' => 'kids party springfield', 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.044, 'position' => 3.2]],
            'pages' => [['page' => 'https://example.com/pricing', 'clicks' => 25, 'impressions' => 400, 'ctr' => 0.06, 'position' => 5.1]],
            'countries' => [['country' => 'usa', 'clicks' => 100, 'impressions' => 3000], ['country' => 'gbr', 'clicks' => 20, 'impressions' => 1000]],
            'devices' => [['device' => 'MOBILE', 'clicks' => 80, 'impressions' => 3000], ['device' => 'DESKTOP', 'clicks' => 40, 'impressions' => 1000]],
        ], $overrides)];
    }

    private function fakePortal(string $status = 'connected'): void
    {
        $this->portalSays([
            self::BASE => Http::response($this->searchStatusBody($status)),
            self::BASE.'/performance*' => Http::response($this->performance()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stubs
     */
    private function portalSays(array $stubs): void
    {
        Http::swap(new Factory);
        Cache::flush();
        Http::fake($stubs);
    }

    private function portalCalls(): int
    {
        return Http::recorded()->count();
    }

    public function test_status_and_performance_are_read_once_then_kept_for_fifteen_minutes(): void
    {
        $this->pair();
        $this->fakePortal();
        $portal = app(PortalSearchConsole::class);

        $portal->status();
        $portal->status();
        $portal->performance(28);
        $portal->performance(28);
        $portal->performance(7);

        $this->assertSame(3, $this->portalCalls(), 'One status, one per range.');

        $portal->status(fresh: true);
        $this->assertSame(4, $this->portalCalls(), 'Refresh asks again.');

        $this->travel(16)->minutes();
        $portal->status();
        $this->assertSame(5, $this->portalCalls(), 'Fifteen minutes later it asks again.');
    }

    public function test_a_site_that_is_not_paired_never_calls_the_portal(): void
    {
        Http::fake();
        $portal = app(PortalSearchConsole::class);

        $this->assertNull($portal->status());
        $this->assertFalse($portal->available());
        $this->assertNull($portal->performance(28));
        $this->assertFalse(app(SearchConsole::class)->isConfigured());

        Http::assertNothingSent();
    }

    public function test_a_failing_portal_is_unavailable_and_is_not_asked_again_at_once(): void
    {
        $this->pair();
        $asked = 0;
        Http::fake(function () use (&$asked): never {
            $asked++;

            throw new \RuntimeException('down');
        });
        $portal = app(PortalSearchConsole::class);

        $this->assertNull($portal->status());
        $this->assertNull($portal->status());
        $this->assertFalse($portal->available());
        $this->assertSame(1, $asked);
    }

    public function test_performance_is_mapped_for_the_cms(): void
    {
        $this->pair();
        $this->fakePortal();

        $p = app(PortalSearchConsole::class)->performance(7);

        $this->assertSame(['clicks' => 120, 'impressions' => 4000, 'ctr' => 0.03, 'position' => 8.44], $p['totals']);
        $this->assertSame(20.0, $p['changes']['clicks']);
        $this->assertSame(-20.0, $p['changes']['impressions']);
        $this->assertSame(-0.6, $p['changes']['position'], 'Places moved; negative is better.');
        $this->assertCount(7, $p['daily'], 'Every day from 3 to 9 September, quiet ones as zeros.');
        $this->assertSame(['2026-09-03', 10], [$p['daily'][0]['date'], $p['daily'][0]['clicks']]);
        $this->assertSame(0, $p['daily'][1]['clicks']);
        $this->assertSame(30, $p['daily'][2]['clicks']);
        $this->assertSame(['United States', 'United Kingdom'], array_column($p['countries'], 'name'));
        $this->assertSame(['Phones', 'Computers'], array_column($p['devices'], 'label'));
        $this->assertSame('kids party springfield', $p['queries'][0]['query']);
        $this->assertSame('https://example.com/pricing', $p['pages'][0]['page']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/performance?days=7') && $request->hasHeader('X-Gadya-Site'));
    }

    public function test_country_codes_are_turned_into_readable_names(): void
    {
        $this->assertSame('US', CountryCodes::alpha2('usa'));
        $this->assertSame('France', CountryCodes::name('fra'));
        $this->assertSame('ZZZ', CountryCodes::name('zzz'));
    }

    public function test_the_card_offers_only_the_advanced_method_without_the_portal(): void
    {
        $this->onHttps();
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertDontSee('Connect Google Search Console')
            ->assertSee('Advanced: use your own Google service account');

        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected', ['available' => false]))]);

        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertDontSee('Connect Google Search Console')
            ->assertSee('Advanced: use your own Google service account');
    }

    public function test_every_state_of_the_card(): void
    {
        $this->onHttps();
        $this->pair();
        $admin = $this->administrator();

        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected')), self::BASE.'/performance*' => Http::response([], 404)]);
        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertSee('Connect Google Search Console')
            ->assertSee('Read-only')
            ->assertSee('Google account that owns or manages')
            ->assertActionExists('connect');

        Cache::flush();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('connected')), self::BASE.'/performance*' => Http::response($this->performance())]);
        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertSee('Connected as')
            ->assertSee('owner@example.com')
            ->assertSee('sc-domain:example.com')
            ->assertSee('Data up to 9 September 2026')
            ->assertActionExists('sync')
            ->assertActionExists('disconnect');

        Cache::flush();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('connected', ['source' => 'agency', 'google_email' => null])), self::BASE.'/performance*' => Http::response($this->performance())]);
        Livewire::actingAs($admin)->test(SearchSettings::class)->assertSee('Connected through Gadya Media');

        Cache::flush();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('needs_reconnect')), self::BASE.'/performance*' => Http::response($this->performance())]);
        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertSee('Needs signing in again')
            ->assertActionExists('reconnect');

        Cache::flush();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('no_property')), self::BASE.'/performance*' => Http::response([], 404)]);
        Livewire::actingAs($admin)->test(SearchSettings::class)
            ->assertSee('has no Search Console property for')
            ->assertActionExists('tryAnother');
    }

    public function test_connecting_sends_the_browser_to_the_url_the_portal_returned(): void
    {
        $this->onHttps();
        $this->pair();
        $this->portalSays([
            self::BASE => Http::response($this->searchStatusBody('not_connected')),
            self::BASE.'/connect' => Http::response(['data' => ['url' => 'https://portal.test/google/search-console/start/abc', 'expires_at' => '2026-09-30T12:00:00Z']], 201),
        ]);

        Livewire::actingAs($this->administrator())->test(SearchSettings::class)
            ->callAction('connect')
            ->assertRedirect('https://portal.test/google/search-console/start/abc');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/search-console/connect')
            && $request['return_url'] === SearchSettings::getUrl()
            && str_starts_with($request['return_url'], 'https://'));
    }

    public function test_a_local_http_site_says_so_instead_of_calling_the_portal(): void
    {
        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected'))]);

        Livewire::actingAs($this->administrator())->test(SearchSettings::class)
            ->assertSee('https address')
            ->callAction('connect')
            ->assertNotified('Connect from the live site');

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/connect'));
    }

    public function test_portal_refusals_and_outages_are_plain_notifications(): void
    {
        $this->onHttps();
        $this->pair();

        foreach ([
            [fn () => Http::response(['message' => 'Google is not configured'], 503), 'not switched on'],
            [fn () => Http::response(['message' => 'bad return_url'], 422), 'live https site'],
            [fn () => throw new ConnectionException('timeout'), 'could not reach Gadya Media'],
        ] as [$response, $words]) {
            Cache::flush();
            $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected')), self::BASE.'/connect' => $response]);

            $result = app(PortalSearchConsole::class)->connectUrl('https://cms.test/admin/search-settings');

            $this->assertNull($result['url']);
            $this->assertStringContainsString($words, $result['error']);

            Livewire::actingAs($this->administrator())->test(SearchSettings::class)
                ->callAction('connect')
                ->assertNotified('Could not start connecting')
                ->assertNoRedirect();
        }
    }

    public function test_every_return_reason_has_a_plain_message(): void
    {
        $this->onHttps();
        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected'))]);
        $admin = $this->administrator();

        foreach (['denied', 'failed', 'exchange', 'scope', 'identity', 'email', 'token', 'no_property'] as $reason) {
            Livewire::actingAs($admin)
                ->withQueryParams(['google' => 'failed', 'reason' => $reason])
                ->test(SearchSettings::class)
                ->assertNotified('Google Search Console was not connected')
                ->assertRedirect(SearchSettings::getUrl());

            $message = PortalSearchConsole::failureMessage($reason, 'example.com');
            $this->assertNotSame('', $message);
            $this->assertStringNotContainsString($reason.'_', $message);
        }

        $this->assertStringContainsString('has no Search Console property for example.com', PortalSearchConsole::failureMessage('no_property', 'example.com'));
        $this->assertStringContainsString('tick the Search Console box', PortalSearchConsole::failureMessage('scope', 'example.com'));
        $this->assertSame('You chose not to share. Nothing was connected.', PortalSearchConsole::failureMessage('denied', 'example.com'));
    }

    public function test_coming_back_connected_thanks_them_and_looks_at_the_connection_afresh(): void
    {
        $this->onHttps();
        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected')), self::BASE.'/performance*' => Http::response([], 404)]);
        $admin = $this->administrator();

        Livewire::actingAs($admin)->test(SearchSettings::class);

        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('connected')), self::BASE.'/performance*' => Http::response($this->performance())]);

        Livewire::actingAs($admin)
            ->withQueryParams(['google' => 'connected'])
            ->test(SearchSettings::class)
            ->assertNotified('Google Search Console is connected');

        $this->assertSame('connected', app(PortalSearchConsole::class)->status()['status']);
        $this->assertSame(1, SearchSnapshot::query()->where('kind', 'query')->count(), 'The first numbers are kept for the dashboard.');
    }

    public function test_disconnect_asks_the_portal_and_sync_is_rate_limited_plainly(): void
    {
        $this->onHttps();
        $this->pair();
        $admin = $this->administrator();
        $this->portalSays([
            self::BASE.'/sync' => Http::sequence()->push([], 202)->push(['message' => 'too soon'], 429)->push(['message' => 'not connected'], 409),
            self::BASE => Http::sequence()->push($this->searchStatusBody('connected'))->push($this->searchStatusBody('not_connected'), 200),
            self::BASE.'/performance*' => Http::response($this->performance()),
        ]);

        $page = Livewire::actingAs($admin)->test(SearchSettings::class);

        $page->callAction('sync')->assertNotified('Refreshing');
        $page->callAction('sync')->assertNotified('Not refreshed');
        $page->callAction('sync')->assertNotified('Not refreshed');

        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected'))]);
        $page->callAction('disconnect')->assertNotified('Disconnected');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === self::BASE);

        $this->portalSays([self::BASE.'/sync' => Http::response(['message' => 'too soon'], 429)]);
        $this->assertStringContainsString('refreshed a moment ago', app(PortalSearchConsole::class)->sync()['error']);

        $this->portalSays([self::BASE.'/sync' => Http::response(['message' => 'no'], 409)]);
        $this->assertStringContainsString('Connect Google Search Console first', app(PortalSearchConsole::class)->sync()['error']);
    }

    public function test_only_people_who_can_manage_settings_reach_it(): void
    {
        $this->onHttps();
        $this->pair();
        $this->fakePortal();

        $this->actingAs($this->editor())->get(SearchSettings::getUrl())->assertForbidden();

        Livewire::actingAs($this->editor())->test(SearchSettings::class)->assertForbidden();
    }

    public function test_redrawing_the_page_never_calls_the_portal_again(): void
    {
        $this->onHttps();
        $this->pair();
        $this->fakePortal();

        $page = Livewire::actingAs($this->administrator())->test(SearchSettings::class);
        $calls = $this->portalCalls();

        $page->fillForm(['property' => 'sc-domain:example.org'])->call('$refresh')->call('$refresh');

        $this->assertSame($calls, $this->portalCalls());
    }

    public function test_the_search_module_uses_the_portal_when_connected_and_fills_the_snapshot(): void
    {
        $this->pair();
        $this->fakePortal();

        $console = app(SearchConsole::class);
        $this->assertSame(SearchConsole::SOURCE_PORTAL, $console->source());
        $this->assertTrue($console->isConfigured());

        $this->artisan('gadya-cms:search-console')->expectsOutputToContain('1 queries and 1 pages')->assertSuccessful();

        $console = app(SearchConsole::class);
        $this->assertSame('kids party springfield', $console->topQueries()->first()->key);
        $this->assertSame(0.044, $console->topQueries()->first()->ctr);
        $this->assertSame('https://example.com/pricing', $console->topPages()->first()->key);
        $this->assertSame(['clicks' => 120, 'impressions' => 4000], $console->totals(), 'The portal\'s real totals, not the sum of the top rows.');
        $this->assertSame('2026-09-09', $console->topQueries()->first()->period_end->toDateString());

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleapis.com'));
    }

    public function test_a_failing_portal_fetch_is_an_error_not_a_crash(): void
    {
        $this->pair();
        $this->portalSays([
            self::BASE => Http::response($this->searchStatusBody('connected')),
            self::BASE.'/performance*' => Http::response(['message' => 'oops'], 500),
        ]);

        $this->artisan('gadya-cms:search-console')->expectsOutputToContain('Gadya Media did not answer')->assertFailed();
    }

    public function test_the_own_service_account_is_used_when_the_portal_has_nothing(): void
    {
        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected'))]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        app(SearchConsole::class)->save(['property' => 'sc-domain:example.com', 'service_account' => json_encode(['client_email' => 'bot@p.iam.gserviceaccount.com', 'private_key' => $pem])]);

        $this->assertSame(SearchConsole::SOURCE_SERVICE_ACCOUNT, app(SearchConsole::class)->source());

        $this->portalSays([
            self::BASE => Http::response($this->searchStatusBody('not_connected')),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token']),
            'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['rows' => [['keys' => ['own key'], 'clicks' => 3, 'impressions' => 9, 'ctr' => 0.3, 'position' => 2]]]),
        ]);

        $this->artisan('gadya-cms:search-console')->assertSuccessful();

        $this->assertSame('own key', app(SearchConsole::class)->topQueries()->first()->key);
    }

    public function test_the_dashboard_shows_the_search_section(): void
    {
        $this->pair();
        $this->fakePortal();

        Livewire::actingAs($this->administrator())->test(Dashboard::class)
            ->assertSee('Average position')
            ->assertSee('8.4')
            ->assertSee('3.0%')
            ->assertSee('Up 20.0%')
            ->assertSee('Down 20.0%')
            ->assertSee('0.6 places higher')
            ->assertSee('kids party springfield')
            ->assertSee('/pricing')
            ->assertSee('United States')
            ->assertSee('Phones')
            ->assertSee('Data up to 9 September 2026; Google reports a few days late.')
            ->assertSeeHtml('aria-label="Clicks per day over the last 28 days')
            ->call('setSearchRange', 7)
            ->assertSet('searchDays', 7)
            ->call('setSearchRange', 11)
            ->assertSet('searchDays', 28);
    }

    public function test_the_dashboard_invites_a_connection_when_there_is_none_and_not_an_error(): void
    {
        $this->pair();
        $this->portalSays([self::BASE => Http::response($this->searchStatusBody('not_connected')), self::BASE.'/performance*' => Http::response([], 404)]);

        Livewire::actingAs($this->administrator())->test(Dashboard::class)
            ->assertSee('See what people searched for to find you.')
            ->assertSee('Connect Google Search Console')
            ->assertDontSee('Average position');
    }

    public function test_the_dashboard_shows_stale_numbers_with_a_warning_when_google_needs_signing_in_again(): void
    {
        $this->pair();
        $this->fakePortal('needs_reconnect');

        Livewire::actingAs($this->administrator())->test(Dashboard::class)
            ->assertSee('Google stopped letting us read your search data')
            ->assertSee('Sign in again')
            ->assertSee('kids party springfield');
    }

    public function test_the_dashboard_says_so_while_the_first_sync_is_pending(): void
    {
        $this->pair();
        $this->portalSays([
            self::BASE => Http::response($this->searchStatusBody('connected', ['last_synced_at' => null])),
            self::BASE.'/performance*' => Http::response($this->performance(['synced_at' => null, 'daily' => []])),
        ]);

        Livewire::actingAs($this->administrator())->test(Dashboard::class)->assertSee('The first numbers are on their way');
    }

    public function test_a_site_without_the_portal_shows_no_new_section_on_the_dashboard(): void
    {
        Http::fake();

        Livewire::actingAs($this->administrator())->test(Dashboard::class)->assertDontSee('See what people searched for to find you.');

        Http::assertNothingSent();
    }

    public function test_the_audit_offers_the_connection_but_never_demands_it(): void
    {
        $check = fn (): array => collect(app(InstallAudit::class)->checks())->firstWhere('label', 'Google Search Console is connected');

        $this->assertSame(InstallAudit::OPTIONAL, $check()['status']);

        $this->pair();
        $this->fakePortal();
        Cache::flush();

        $this->assertSame(InstallAudit::OK, $check()['status']);
    }

    public function test_get_found_mentions_the_connection(): void
    {
        $this->pair();
        $this->fakePortal();

        $this->actingAs($this->administrator())->get(GetFound::getUrl())->assertOk()->assertSee('Google Search Console is connected');
    }

    public function test_saving_an_unrelated_filament_form_still_works_with_the_dashboard_and_card(): void
    {
        $this->onHttps();
        $this->pair();
        $this->fakePortal();

        Livewire::actingAs($this->administrator())->test(SearchSettings::class)
            ->fillForm(['pagespeed_key' => 'AIza-key'])
            ->call('save')
            ->assertNotified('Saved');
    }
}
