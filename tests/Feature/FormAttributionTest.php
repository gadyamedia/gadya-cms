<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Forms\Attribution;
use Gadya\Cms\Forms\Builder\FormExport;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Portal\SubmissionPush;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;

/**
 * Where each enquiry came from - the campaign, the landing page, the page
 * it was sent from - and exactly what the visitor consented to, down to
 * the version of the privacy policy.
 */
class FormAttributionTest extends TestCase
{
    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();

        $this->form = Form::factory()->published()->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Your name', 'required' => true],
            ['type' => 'consent', 'key' => 'privacy', 'label' => 'I agree to the privacy policy.', 'required' => true],
        ])->create(['slug' => 'contact']);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function answers(array $answers = []): array
    {
        $this->travel(-10)->seconds();
        $seal = app(SpamGuard::class)->seal('contact');
        $this->travelBack();

        return ['_t' => $seal, '_path' => '/contact', 'name' => 'Anna', 'privacy' => '1', ...$answers];
    }

    public function test_the_form_carries_the_inputs_the_script_fills_with_where_the_visit_began(): void
    {
        $html = (string) Blade::render('<x-gadya-cms::form form="contact" />');

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'referrer', 'landing_page'] as $key) {
            $this->assertStringContainsString('name="_attribution['.$key.']"', $html);
        }

        $this->assertStringContainsString('name="_locale" value="en"', $html);
        $this->assertStringContainsString('sessionStorage.setItem(firstTouchKey', $html);
    }

    public function test_what_the_script_noted_is_kept_and_the_address_fills_the_gaps(): void
    {
        $this->postJson('/cms/forms/contact', $this->answers([
            '_attribution' => [
                'utm_source' => 'google',
                'gclid' => 'Cj0KCQ',
                'referrer' => 'https://www.google.com/',
                'landing_page' => 'http://localhost/kitchens?utm_source=google',
                'bogus' => 'ignored',
            ],
        ]), ['Referer' => 'http://localhost/contact?utm_medium=cpc&utm_campaign=spring'])->assertOk();

        $attribution = FormSubmission::query()->sole()->meta['attribution'];

        $this->assertSame('google', $attribution['utm_source'], 'First touch, as the script noted it, wins.');
        $this->assertSame('cpc', $attribution['utm_medium'], 'The page it was sent from fills what the script did not note.');
        $this->assertSame('spring', $attribution['utm_campaign']);
        $this->assertSame('Cj0KCQ', $attribution['gclid']);
        $this->assertSame('https://www.google.com/', $attribution['referrer']);
        $this->assertSame('http://localhost/kitchens?utm_source=google', $attribution['landing_page']);
        $this->assertSame('http://localhost/contact?utm_medium=cpc&utm_campaign=spring', $attribution['page_url']);
        $this->assertSame('en', $attribution['locale']);
        $this->assertSame('default', $attribution['site']);
        $this->assertSame('127.0.0.1', $attribution['ip']);
        $this->assertArrayNotHasKey('bogus', $attribution);
    }

    public function test_a_referrer_on_this_site_or_a_landing_page_on_another_is_not_believed(): void
    {
        $this->postJson('/cms/forms/contact', $this->answers([
            '_attribution' => ['referrer' => 'http://localhost/about', 'landing_page' => 'https://evil.example/'],
        ]))->assertOk();

        $attribution = FormSubmission::query()->sole()->meta['attribution'];

        $this->assertArrayNotHasKey('referrer', $attribution);
        $this->assertSame('http://localhost/contact', $attribution['landing_page'], 'Without a first page, the page it was sent from stands in.');
    }

    public function test_without_the_script_the_first_request_of_the_visit_is_remembered(): void
    {
        $this->get('/forms/contact?utm_source=facebook&utm_campaign=autumn&fbclid=IwAR', ['Referer' => 'https://m.facebook.com/'])->assertOk();
        $this->get('/forms/contact?utm_source=later', ['Referer' => 'https://elsewhere.example/'])->assertOk();

        $this->postJson('/cms/forms/contact', $this->answers(), ['Referer' => 'http://localhost/forms/contact'])->assertOk();

        $attribution = FormSubmission::query()->sole()->meta['attribution'];

        $this->assertSame('facebook', $attribution['utm_source'], 'The first page of the visit, not a later one.');
        $this->assertSame('autumn', $attribution['utm_campaign']);
        $this->assertSame('IwAR', $attribution['fbclid']);
        $this->assertSame('https://m.facebook.com/', $attribution['referrer']);
        $this->assertSame('http://localhost/forms/contact?fbclid=IwAR&utm_campaign=autumn&utm_source=facebook', $attribution['landing_page']);
    }

    public function test_the_address_is_masked_when_the_site_says_so_or_the_visitor_refused_analytics(): void
    {
        config(['gadya-cms.forms.builder.attribution.ip' => 'masked']);
        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();
        $this->assertSame('127.0.0.0', FormSubmission::query()->latest('id')->first()->meta['attribution']['ip']);

        config(['gadya-cms.forms.builder.attribution.ip' => 'full']);
        $this->withHeader('Sec-GPC', '1')->postJson('/cms/forms/contact', $this->answers())->assertOk();
        $this->assertSame('127.0.0.0', FormSubmission::query()->latest('id')->first()->meta['attribution']['ip']);

        config(['gadya-cms.forms.builder.attribution.ip' => 'none']);
        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();
        $this->assertArrayNotHasKey('ip', FormSubmission::query()->latest('id')->first()->meta['attribution']);

        $this->assertSame('2001:db8:85a3::', Attribution::mask('2001:db8:85a3:8d3:1319:8a2e:370:7348'));
    }

    public function test_it_is_shown_in_the_inbox_the_spreadsheet_and_sent_to_the_portal(): void
    {
        $this->postJson('/cms/forms/contact', $this->answers(['_attribution' => ['utm_source' => 'google', 'utm_campaign' => 'spring']]))->assertOk();
        $submission = FormSubmission::query()->sole();

        $this->actingAs($this->editor());
        $detail = view('gadya-cms::filament.submissions.detail', ['submission' => $submission])->render();
        $this->assertStringContainsString('Campaign source', $detail);
        $this->assertStringContainsString('spring', $detail);

        $rows = app(FormExport::class)->rows(FormSubmission::query()->get());
        $source = array_search('Campaign source', $rows[0], true);
        $this->assertNotFalse($source);
        $this->assertSame('google', $rows[1][$source]);

        $payload = app(SubmissionPush::class)->payload($submission);
        $this->assertSame('google', $payload['attribution']->utm_source);
        $this->assertObjectNotHasProperty('ip', $payload['attribution'], 'The portal is not told the visitor\'s address twice.');
        $this->assertSame(['name' => 'Anna', 'privacy' => 'Yes'], (array) $payload['fields'], 'The answers are unchanged.');
    }

    public function test_a_consent_keeps_the_policy_version_from_config(): void
    {
        config(['gadya-cms.privacy.policy_version' => 'v3', 'gadya-cms.privacy.policy_url' => 'https://example.com/privacy']);

        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();

        $consent = FormSubmission::query()->sole()->meta['consents']['privacy'];
        $this->assertSame('I agree to the privacy policy.', $consent['text']);
        $this->assertSame('127.0.0.1', $consent['ip']);
        $this->assertSame('v3', $consent['policy_version']);
        $this->assertSame('https://example.com/privacy', $consent['policy_url']);
        $this->assertNotNull($consent['at']);
    }

    public function test_a_policy_on_a_cms_page_is_dated_by_when_its_wording_went_live(): void
    {
        config(['gadya-cms.privacy.policy_url' => '/privacy-policy']);
        $repository = app(SiteContentRepository::class);

        $document = $repository->draft();
        $document['pages']['privacy-policy'] = ['title' => 'Privacy policy', 'type' => 'content', 'body' => 'We keep what you send us.'];
        $repository->saveDraft($document);

        $this->travelTo(now()->subDays(10)->startOfSecond());
        $first = app(PublishSiteContent::class)->handle();
        $this->travelBack();

        /* Published again with the policy unchanged: still the same version. */
        app(PublishSiteContent::class)->handle();
        $repository->flushPublishedCache();

        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();
        $this->assertSame($first->published_at->toIso8601String(), FormSubmission::query()->latest('id')->first()->meta['consents']['privacy']['policy_version']);

        $document['pages']['privacy-policy']['body'] = 'We keep what you send us, for a year.';
        $repository->saveDraft($document);
        $second = app(PublishSiteContent::class)->handle();
        $repository->flushPublishedCache();

        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();
        $this->assertSame($second->published_at->toIso8601String(), FormSubmission::query()->latest('id')->first()->meta['consents']['privacy']['policy_version']);
    }

    public function test_a_policy_somewhere_else_has_no_version_to_give(): void
    {
        config(['gadya-cms.privacy.policy_url' => 'https://lawyers.example/privacy']);

        $this->postJson('/cms/forms/contact', $this->answers())->assertOk();

        $consent = FormSubmission::query()->sole()->meta['consents']['privacy'];
        $this->assertNull($consent['policy_version']);
        $this->assertSame('https://lawyers.example/privacy', $consent['policy_url']);
    }
}
