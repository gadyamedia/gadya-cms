<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Pages\PrivacySettings;
use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Privacy\Consent;
use Gadya\Cms\Privacy\TrackerScan;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Support\PortalSummary;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * The privacy banner, for the New Jersey Data Privacy Act: nothing
 * changes until it is switched on; then third-party tags wait for the
 * visitor's say-so, Global Privacy Control is a no to marketing, and the
 * CMS's own counting stops for anyone who refuses analytics.
 */
class PrivacyConsentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function switchOn(array $settings = []): void
    {
        $repository = app(SiteContentRepository::class);
        $repository->saveDraft([...$repository->draft(), Consent::KEY => ['banner_enabled' => true, 'policy_url' => '/privacy', ...$settings]]);
        app(PublishSiteContent::class)->handle();
        $repository->flushPublishedCache();
    }

    private function choice(bool $analytics, bool $marketing): string
    {
        return json_encode(['v' => 1, 'necessary' => true, 'analytics' => $analytics, 'marketing' => $marketing, 'gpc' => false, 'at' => now()->toIso8601String()]);
    }

    private function request(?string $cookie = null, bool $gpc = false): Request
    {
        $request = Request::create('/', 'GET', cookies: $cookie === null ? [] : [Consent::cookieName() => $cookie]);

        if ($gpc) {
            $request->headers->set('Sec-GPC', '1');
        }

        return $request;
    }

    public function test_an_existing_site_changes_nothing_until_the_banner_is_switched_on(): void
    {
        $this->assertSame('', trim(Blade::render('<x-gadya-cms::consent-banner />')));
        $this->assertSame('', trim(Blade::render('<x-gadya-cms::privacy-choices-link />')), 'No banner and no policy: no link.');

        $analytics = Blade::render('<x-gadya-cms::consented-script category="analytics" src="https://example.test/a.js" async />');
        $this->assertStringContainsString('<script src="https://example.test/a.js"', $analytics);
        $this->assertStringNotContainsString('text/plain', $analytics);

        $this->assertTrue(app(Consent::class)->allows(Consent::MARKETING, $this->request()));
        $this->assertSame(['banner_enabled' => false, 'honours_gpc' => true], app(PortalSummary::class)->build()['consent']);
    }

    public function test_global_privacy_control_keeps_marketing_out_even_without_the_banner(): void
    {
        $consent = app(Consent::class);

        $this->assertFalse($consent->allows(Consent::MARKETING, $this->request(gpc: true)));
        $this->assertTrue($consent->allows(Consent::ANALYTICS, $this->request(gpc: true)), 'GPC is about selling data and targeted ads, not counting visits.');

        $marketing = Blade::render('<x-gadya-cms::consented-script category="marketing">fbq("init", "1");</x-gadya-cms::consented-script>');
        $this->assertStringContainsString('<script type="text/plain" data-cms-consent="marketing"', $marketing, 'The browser decides, so a cached page still honours the signal.');
        $this->assertStringContainsString('navigator.globalPrivacyControl', $marketing);
    }

    public function test_once_switched_on_tags_wait_for_the_visitors_choice(): void
    {
        $this->switchOn();

        $html = Blade::render(<<<'BLADE'
            <x-gadya-cms::consented-script category="analytics" src="https://www.googletagmanager.com/gtag/js?id=G-1" async />
            <x-gadya-cms::consented-script category="marketing">fbq('track', 'PageView');</x-gadya-cms::consented-script>
            <x-gadya-cms::privacy-choices-link />
            <x-gadya-cms::consent-banner />
            BLADE);

        $this->assertStringContainsString('<script type="text/plain" data-cms-consent="analytics" data-cms-src="https://www.googletagmanager.com/gtag/js?id=G-1"', $html);
        $this->assertStringContainsString('data-cms-consent="marketing"', $html);
        $this->assertStringContainsString("fbq('track', 'PageView');", $html);
        $this->assertStringContainsString('<script type="text/plain" data-cms-consent="marketing" data-cms-type="module">', Blade::render('<x-gadya-cms::consented-script category="marketing" type="module">x()</x-gadya-cms::consented-script>'), 'A tag cannot wake itself by naming its own type.');
        $this->assertSame(1, substr_count($html, 'data-cms-consent-runtime'), 'One runtime however many tags and banners.');

        $this->assertStringContainsString('<a class="cms-privacy-choices" href="/privacy" data-cms-consent-open>Your privacy choices</a>', $html);
        $this->assertStringContainsString('data-cms-consent-banner', $html);
        $this->assertStringContainsString('>Accept all</button>', $html);
        $this->assertStringContainsString('>Reject all</button>', $html);
        $this->assertStringContainsString('aria-controls="cms-consent-choices"', $html);
        $this->assertStringContainsString('data-cms-consent-category="marketing"', $html);
        $this->assertStringContainsString('Global Privacy Control', $html);
    }

    public function test_the_choice_in_the_cookie_is_what_is_allowed(): void
    {
        $this->switchOn();
        $consent = app(Consent::class);

        $this->assertFalse($consent->allows(Consent::ANALYTICS, $this->request()), 'No choice yet: wait.');
        $this->assertTrue($consent->allows(Consent::NECESSARY, $this->request()));

        $analyticsOnly = $this->choice(analytics: true, marketing: false);
        $this->assertTrue($consent->allows(Consent::ANALYTICS, $this->request($analyticsOnly)));
        $this->assertFalse($consent->allows(Consent::MARKETING, $this->request($analyticsOnly)));

        $everything = rawurlencode($this->choice(analytics: true, marketing: true));
        $this->assertTrue($consent->allows(Consent::MARKETING, $this->request($everything)), 'As the browser writes it, URL-encoded.');
        $this->assertFalse($consent->allows(Consent::MARKETING, $this->request($everything, gpc: true)), 'GPC wins over an earlier yes.');

        $this->assertFalse($consent->allows(Consent::ANALYTICS, $this->request('not json')));
    }

    public function test_someone_who_refuses_analytics_is_not_counted_by_the_cms_itself(): void
    {
        Route::middleware('web')->get('/visit', fn (): string => 'hello');

        $this->withUnencryptedCookie(Consent::cookieName(), $this->choice(analytics: false, marketing: false))->get('/visit')->assertOk();
        $this->assertSame(0, PageView::query()->count());

        $this->withUnencryptedCookie(Consent::cookieName(), $this->choice(analytics: true, marketing: false))->get('/visit')->assertOk();
        $this->assertSame(1, PageView::query()->count());
    }

    public function test_a_refusers_phone_tap_is_counted_as_nobody_in_particular(): void
    {
        $refused = $this->request($this->choice(analytics: false, marketing: false));

        $this->assertNotSame(VisitorFingerprint::hash($refused), VisitorFingerprint::hash($refused), 'A fresh value every time, never the daily identifier.');
        $this->assertSame(VisitorFingerprint::hash($this->request()), VisitorFingerprint::hash($this->request()));

        $this->withUnencryptedCookie(Consent::cookieName(), $this->choice(analytics: false, marketing: false))
            ->postJson(route('gadya-cms.events.store'), ['name' => 'phone_click', 'path' => '/'])
            ->assertOk();

        $this->assertSame(1, AnalyticsEvent::query()->where('name', 'phone_click')->count());
    }

    public function test_an_administrator_switches_it_on_and_words_every_part_of_it(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(PrivacySettings::class)
            ->assertFormSet(['banner_enabled' => false, 'honour_gpc' => true])
            ->assertFormFieldExists('text.accept')
            ->fillForm([
                'banner_enabled' => true,
                'policy_url' => '/privacy-policy',
                'text' => [
                    'heading' => 'Cookies at Mo’s Bagels',
                    'message' => 'We bake bagels, not cookies. Mostly.',
                    'accept' => 'Sure, all of it',
                    'reject' => 'No thanks',
                    'choose' => 'Let me pick',
                    'save' => 'Keep these',
                    'legend' => 'Pick what we may use',
                    'policy' => 'How we look after your data',
                    'link' => 'Cookie settings',
                    'gpc' => 'Your browser asked us not to sell your data.',
                ],
                'categories' => [
                    'necessary' => ['name' => 'Must-haves', 'description' => 'The oven has to be on.'],
                    'analytics' => ['name' => 'Counting', 'description' => 'How many people look at the menu.'],
                    'marketing' => ['name' => 'Ads', 'description' => 'Instagram and Google ads.'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(Consent::class)->draft()['banner_enabled']);
        $this->assertFalse(app(Consent::class)->published()['banner_enabled'], 'Nothing changes on the site before Publish.');

        app(PublishSiteContent::class)->handle();
        app(SiteContentRepository::class)->flushPublishedCache();

        $html = Blade::render('<x-gadya-cms::consent-banner /><x-gadya-cms::privacy-choices-link />');

        foreach ([
            'Cookies at Mo’s Bagels', 'We bake bagels, not cookies. Mostly.', '>Sure, all of it</button>', '>No thanks</button>',
            '>Let me pick</button>', '>Keep these</button>', '>Pick what we may use</legend>', '>How we look after your data</a>',
            'href="/privacy-policy" data-cms-consent-open>Cookie settings</a>', 'Your browser asked us not to sell your data.',
            '>Must-haves</label>', 'The oven has to be on.', '>Counting</label>', 'How many people look at the menu.', '>Ads</label>', 'Instagram and Google ads.',
        ] as $words) {
            $this->assertStringContainsString($words, $html);
        }

        $this->assertStringNotContainsString('Accept all', $html);
        $this->assertSame(['banner_enabled' => true, 'honours_gpc' => true], app(PortalSummary::class)->build()['consent']);
    }

    public function test_a_blank_box_keeps_the_default_wording_and_its_translation(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(PrivacySettings::class)
            ->fillForm(['banner_enabled' => true, 'text' => ['heading' => 'Cookies'], 'categories' => ['marketing' => ['name' => 'Ads']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = app(SiteContentRepository::class)->draft()[Consent::KEY];

        $this->assertSame(['heading' => 'Cookies'], $stored['text'], 'Only what she wrote is kept, so the rest can follow a translation.');
        $this->assertSame(['marketing' => ['name' => 'Ads']], $stored['categories']);

        app()->setLocale('es');
        app('translator')->addLines(['*.Reject all' => 'Rechazar todo'], 'es');

        $settings = app(Consent::class)->draft();

        $this->assertSame('Cookies', $settings['text']['heading']);
        $this->assertSame('Rechazar todo', $settings['text']['reject']);
        $this->assertSame('Ads', $settings['categories']['marketing']['name']);
        $this->assertSame('Analytics', $settings['categories']['analytics']['name']);
    }

    public function test_a_policy_address_must_be_a_page_or_a_web_address(): void
    {
        Livewire::actingAs($this->administrator())
            ->test(PrivacySettings::class)
            ->fillForm(['policy_url' => 'javascript:alert(1)'])
            ->call('save')
            ->assertHasFormErrors(['policy_url']);
    }

    public function test_an_editor_without_the_settings_ability_cannot_change_it(): void
    {
        $this->actingAs($this->editor())->get('/admin/privacy-choices')->assertForbidden();
    }

    public function test_the_audit_flags_trackers_loaded_without_asking(): void
    {
        $gtag = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><script>fbq("init")</script>';

        $this->assertSame(['Google Analytics / Tag Manager', 'Meta Pixel'], TrackerScan::found($gtag));
        $this->assertTrue(TrackerScan::check('<p>No trackers</p>', false)['passed']);

        $check = TrackerScan::check($gtag, false);
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('Settings → Privacy choices', $check['fix']);

        $wrapped = '<x-gadya-cms::consented-script category="marketing" src="https://www.googletagmanager.com/gtag/js?id=G-1" /><x-gadya-cms::consent-banner />';
        $this->assertTrue(TrackerScan::check($wrapped, true)['passed']);
    }
}
