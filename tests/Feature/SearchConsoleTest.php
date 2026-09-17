<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Pages\SearchSettings;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Models\SearchSnapshot;
use Gadya\Cms\Search\GoogleServiceAccount;
use Gadya\Cms\Search\PageSpeed;
use Gadya\Cms\Search\SearchConsole;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class SearchConsoleTest extends TestCase
{
    private function serviceAccountJson(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) json_encode(['type' => 'service_account', 'client_email' => 'bot@project.iam.gserviceaccount.com', 'private_key' => $pem]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_a_service_account_signs_a_jwt_and_exchanges_it_for_a_token(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token'])]);

        $token = (new GoogleServiceAccount($this->serviceAccountJson()))->token([SearchConsole::SCOPE]);

        $this->assertSame('ya29.token', $token);
        Http::assertSent(function ($request): bool {
            $assertion = $request['assertion'];
            [$header, $claims] = explode('.', $assertion);
            $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $claims['iss'] === 'bot@project.iam.gserviceaccount.com'
                && $claims['scope'] === SearchConsole::SCOPE
                && substr_count($assertion, '.') === 2;
        });
    }

    public function test_a_key_that_is_not_a_service_account_is_refused_on_save(): void
    {
        $this->expectExceptionMessage('not a Google service account key');

        app(SearchConsole::class)->save(['property' => 'sc-domain:example.com', 'service_account' => '{"hello":"world"}']);
    }

    public function test_fetching_keeps_the_latest_queries_and_pages_as_a_snapshot(): void
    {
        app(SearchConsole::class)->save(['property' => 'sc-domain:example.com', 'service_account' => $this->serviceAccountJson()]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token']),
            'www.googleapis.com/webmasters/v3/sites/*' => Http::sequence()
                ->push(['rows' => [['keys' => ['kids party springfield'], 'clicks' => 40, 'impressions' => 900, 'ctr' => 0.044, 'position' => 3.2]]])
                ->push(['rows' => [['keys' => ['https://example.com/pricing'], 'clicks' => 25, 'impressions' => 400, 'ctr' => 0.06, 'position' => 5.1]]]),
        ]);

        $this->artisan('gadya-cms:search-console')->expectsOutputToContain('1 queries and 1 pages')->assertSuccessful();

        $console = app(SearchConsole::class);

        $this->assertSame('kids party springfield', $console->topQueries()->first()->key);
        $this->assertSame(40, $console->topQueries()->first()->clicks);
        $this->assertSame(['clicks' => 40, 'impressions' => 900], $console->totals());
        $this->assertSame('https://example.com/pricing', $console->topPages()->first()->key);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), rawurlencode('sc-domain:example.com')) && $request->hasHeader('Authorization', 'Bearer ya29.token'));
    }

    public function test_fetching_again_replaces_the_previous_snapshot(): void
    {
        app(SearchConsole::class)->save(['property' => 'sc-domain:example.com', 'service_account' => $this->serviceAccountJson()]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token']),
            'www.googleapis.com/webmasters/v3/sites/*' => Http::response(['rows' => [['keys' => ['only one'], 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1]]]),
        ]);

        app(SearchConsole::class)->fetch();
        app(SearchConsole::class)->fetch();

        $this->assertSame(2, SearchSnapshot::query()->count(), 'One query row and one page row, not four.');
    }

    public function test_nothing_is_fetched_until_it_is_set_up(): void
    {
        Http::fake();

        $this->artisan('gadya-cms:search-console')->expectsOutputToContain('not set up')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_the_settings_screen_stores_the_key_encrypted_and_only_for_administrators(): void
    {
        $this->actingAs($this->editor())->get(SearchSettings::getUrl())->assertForbidden();

        Livewire::actingAs($this->administrator())
            ->test(SearchSettings::class)
            ->fillForm(['property' => 'https://example.com/', 'service_account' => $this->serviceAccountJson(), 'pagespeed_key' => 'AIza-key'])
            ->call('save')
            ->assertNotified('Saved');

        $console = app(SearchConsole::class);

        $this->assertTrue($console->isConfigured());
        $this->assertSame('bot@project.iam.gserviceaccount.com', $console->account()?->email());
        $this->assertSame('AIza-key', app(PageSpeed::class)->key());
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(Option::query()->pluck('value')));
    }
}
