<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Seo\SiteSetup;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class GetFoundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_robots_txt_declares_content_signals_for_every_group(): void
    {
        config(['gadya-cms.seo.ai_crawlers' => ['allow' => ['GPTBot'], 'block' => []]]);

        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("User-agent: GPTBot\nDisallow: /admin\nDisallow: /cms\nAllow: /\nContent-Signal: search=yes, ai-input=yes, ai-train=no", $robots);
        $this->assertStringContainsString("User-agent: *\nDisallow: /admin\nDisallow: /cms\nContent-Signal: search=yes, ai-input=yes, ai-train=no", $robots);
    }

    public function test_content_signals_can_be_left_out(): void
    {
        config(['gadya-cms.seo.content_signals' => []]);

        $this->assertStringNotContainsString('Content-Signal', $this->get('/robots.txt')->getContent());
    }

    public function test_the_home_page_points_agents_at_the_sitemap_and_llms_txt(): void
    {
        Route::middleware('web')->get('/', fn () => response('<html>home</html>'));
        Route::middleware('web')->get('/about', fn () => response('<html>about</html>'));

        $this->get('/')
            ->assertOk()
            ->assertHeader('Link', '<'.url('/llms.txt').'>; rel="describedby"; type="text/markdown", <'.url('/sitemap.xml').'>; rel="sitemap"; type="application/xml"');

        $this->get('/about')->assertOk()->assertHeaderMissing('Link');

        config(['gadya-cms.seo.link_headers' => false]);

        $this->get('/')->assertOk()->assertHeaderMissing('Link');
    }

    public function test_the_dns_checklist_says_what_each_domain_has_and_lacks(): void
    {
        config(['gadya-cms.seo.domains' => ['https://www.parties.co/', 'parties.com']]);

        $this->fakeDns([
            'parties.co' => ['A' => ['203.0.113.10'], 'NS' => ['ns49.domaincontrol.com.'], 'TXT' => []],
            'www.parties.co' => ['CNAME' => ['parties.co.']],
            'parties.com' => ['A' => ['198.51.100.7'], 'TXT' => ['"v=spf1 -all"']],
            'www.parties.com' => ['A' => ['198.51.100.7']],
        ]);

        $setup = app(SiteSetup::class);

        $this->assertSame(['parties.co', 'parties.com'], $setup->domains());
        $this->assertSame('https://parties.co/sitemap.xml', $setup->sitemapUrl());
        $this->assertSame('GoDaddy', $setup->dnsHost('parties.co'));

        $main = collect($setup->records('parties.co'))->keyBy(fn (array $record): string => $record['type'].' '.$record['name']);

        $this->assertSame('ok', $main['A @']['status']);
        $this->assertSame('ok', $main['CNAME www']['status']);
        $this->assertSame('missing', $main['TXT @']['status'], 'No Search Console verification yet.');

        $other = collect($setup->records('parties.com'))->keyBy(fn (array $record): string => $record['type'].' '.$record['name']);

        $this->assertSame('wrong', $other['A @']['status'], 'A second domain parked elsewhere is flagged.');
        $this->assertSame('203.0.113.10', $other['A @']['value'], 'It is told to point at the main site\'s server.');
        $this->assertSame(['198.51.100.7'], $other['A @']['found']);
        $this->assertSame('ok', $other['TXT @']['status']);
    }

    public function test_mail_records_are_asked_for_only_when_the_site_sends_from_its_domain(): void
    {
        config(['gadya-cms.seo.domains' => ['parties.co'], 'mail.default' => 'postmark', 'mail.from.address' => 'hello@parties.co']);

        $this->fakeDns(['parties.co' => ['A' => ['203.0.113.10'], 'TXT' => ['"google-site-verification=abc"']]]);

        $records = collect(app(SiteSetup::class)->records('parties.co'));

        $this->assertSame('ok', $records->firstWhere('value', 'google-site-verification=… (Search Console gives you this)')['status']);
        $this->assertSame('missing', $records->firstWhere('value', 'v=spf1 include:spf.mtasv.net ~all')['status']);
        $this->assertSame('missing', $records->firstWhere('name', '_dmarc')['status']);

        config(['mail.from.address' => 'hello@elsewhere.test']);

        $this->assertNull(collect(app(SiteSetup::class)->records('parties.co'))->firstWhere('name', '_dmarc'));
    }

    public function test_a_local_address_is_never_looked_up(): void
    {
        Http::fake();

        $this->assertSame(['cms.test'], app(SiteSetup::class)->domains());

        app(SiteSetup::class)->records('cms.test');

        Http::assertNothingSent();
    }

    public function test_the_live_check_explains_a_robots_txt_the_web_server_swallowed(): void
    {
        Http::fake([
            url('/robots.txt') => Http::response('User-agent: *', 404),
            '*' => Http::response('ok'),
        ]);

        $checks = collect(app(SiteSetup::class)->liveChecks())->keyBy('path');

        $this->assertFalse($checks['/robots.txt']['ok']);
        $this->assertSame(404, $checks['/robots.txt']['status']);
        $this->assertStringContainsString('location = /robots.txt', $checks['/robots.txt']['fix']);
        $this->assertTrue($checks['/sitemap.xml']['ok']);
    }

    public function test_the_page_is_for_people_who_manage_settings(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $this->actingAs($this->administrator())
            ->get('/admin/get-found')
            ->assertOk()
            ->assertSee('Add your sitemap to Google')
            ->assertSee(url('/sitemap.xml'))
            ->assertSee('DNS for cms.test');

        $this->actingAs($this->visitor())->get('/admin/get-found')->assertForbidden();
    }

    /**
     * Answers DNS-over-HTTPS questions from a table instead of the network.
     *
     * @param  array<string, array<string, list<string>>>  $zone
     */
    private function fakeDns(array $zone): void
    {
        $types = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'TXT' => 16, 'CAA' => 257];

        Http::fake(['cloudflare-dns.com/*' => function (Request $request) use ($zone, $types) {
            $name = $request['name'];
            $type = $request['type'];

            return Http::response([
                'Status' => 0,
                'Answer' => array_map(
                    fn (string $data): array => ['name' => $name, 'type' => $types[$type], 'data' => $data],
                    $zone[$name][$type] ?? [],
                ),
            ]);
        }]);
    }
}
