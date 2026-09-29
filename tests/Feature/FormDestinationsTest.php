<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Events\FormSubmitted;
use Gadya\Cms\Filament\Resources\Forms\Pages\EditForm;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Forms\Destinations\DestinationMap;
use Gadya\Cms\Forms\Destinations\FormDestination;
use Gadya\Cms\Forms\Destinations\FormDestinations;
use Gadya\Cms\Forms\SubmissionContext;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Tests\Fixtures\Lead;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;

/**
 * Handing an enquiry on to the site's own records once it is in the
 * inbox - a lead in the site's own table, with where it came from and
 * what they consented to - without the visitor ever seeing it fail.
 */
class FormDestinationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();

        config([
            'gadya-cms.privacy.policy_version' => '2026-03',
            'gadya-cms.privacy.policy_url' => '/privacy-policy',
            'gadya-cms.forms.builder.attribution.site' => ['localhost' => 'aleksey'],
            'gadya-cms.forms.builder.destinations.leads' => [
                'label' => 'Leads',
                'model' => Lead::class,
                'map' => [
                    'name' => 'name',
                    'email' => 'email',
                    'message' => 'message',
                    'source' => '@utm_source',
                    'landing_page' => '@landing_page',
                    'brand' => '@site',
                    'consented_at' => '@consent.at',
                    'privacy_version' => '@consent.policy_version',
                    'locale' => '@locale',
                ],
                'defaults' => ['status' => 'new'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function contact(array $settings = ['destinations' => ['leads']]): Form
    {
        return Form::factory()->published()->withSettings($settings)->withFields([
            ['type' => 'name', 'key' => 'name', 'label' => 'Your name', 'required' => true],
            ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
            ['type' => 'long_text', 'key' => 'message', 'label' => 'Message'],
            ['type' => 'consent', 'key' => 'privacy', 'label' => 'I agree to the privacy policy.', 'required' => true],
        ])->create(['slug' => 'contact', 'title' => 'Contact']);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function answers(Form $form, array $answers = []): array
    {
        $this->travel(-10)->seconds();
        $seal = app(SpamGuard::class)->seal($form->slug);
        $this->travelBack();

        return [
            '_t' => $seal,
            '_path' => '/contact',
            'name' => ['first' => 'Anna', 'last' => 'Petrova'],
            'email' => 'Anna@Example.com',
            'message' => 'We would like a new kitchen.',
            'privacy' => '1',
            '_attribution' => ['utm_source' => 'google', 'utm_campaign' => 'spring', 'landing_page' => 'http://localhost/kitchens?utm_source=google'],
            ...$answers,
        ];
    }

    public function test_an_enquiry_is_saved_to_the_sites_own_model_with_its_source_and_consent(): void
    {
        $form = $this->contact();

        $this->postJson('/cms/forms/contact', $this->answers($form))->assertOk()->assertJson(['ok' => true]);

        $submission = FormSubmission::query()->sole();
        $lead = Lead::query()->sole();

        $this->assertSame('Anna Petrova', $lead->name);
        $this->assertSame('anna@example.com', $lead->email);
        $this->assertSame('We would like a new kitchen.', $lead->message);
        $this->assertSame('new', $lead->status, 'A default fills what the map leaves empty.');
        $this->assertSame('google', $lead->source);
        $this->assertSame('http://localhost/kitchens?utm_source=google', $lead->landing_page);
        $this->assertSame('aleksey', $lead->brand);
        $this->assertSame('en', $lead->locale);
        $this->assertSame('2026-03', $lead->privacy_version);
        $this->assertTrue($lead->consented_at->equalTo($submission->created_at));

        $this->assertTrue($submission->meta['destinations']['leads']['ok']);
        $this->assertSame('Lead #'.$lead->getKey(), $submission->meta['destinations']['leads']['result']);
        $this->assertSame('google', $submission->meta['attribution']['utm_source']);

        $this->actingAs($this->editor());
        $this->assertStringContainsString('Saved to Leads', view('gadya-cms::filament.submissions.detail', ['submission' => $submission])->render());
    }

    public function test_tokens_questions_and_values_are_told_apart(): void
    {
        $form = $this->contact();
        $this->postJson('/cms/forms/contact', $this->answers($form, ['message' => '']))->assertOk();

        $submission = FormSubmission::query()->sole();
        $context = SubmissionContext::fromSubmission($submission, $form->title);
        $keys = array_column($form->schema()->inputs(), 'key');

        $resolved = DestinationMap::resolve([
            'name' => 'name',
            'message' => 'message',
            'status' => 'hot',
            'email_literal' => '=email',
            'source' => '@source',
            'campaign' => '@utm_campaign',
            'consent' => '@consent.given',
            'consent_text' => '@consent.privacy.text',
            'policy' => '@consent.policy_url',
            'form' => '@form_title',
            'sender' => '@email',
            'nothing' => '@gclid',
        ], $submission->data, $context, $keys);

        $this->assertSame('Anna Petrova', $resolved['name']);
        $this->assertNull($resolved['message'], 'An unanswered question gives nothing, never its own name.');
        $this->assertSame('hot', $resolved['status']);
        $this->assertSame('email', $resolved['email_literal']);
        $this->assertSame('google', $resolved['source']);
        $this->assertSame('spring', $resolved['campaign']);
        $this->assertTrue($resolved['consent']);
        $this->assertSame('I agree to the privacy policy.', $resolved['consent_text']);
        $this->assertSame('/privacy-policy', $resolved['policy']);
        $this->assertSame('Contact', $resolved['form']);
        $this->assertSame('anna@example.com', $resolved['sender']);
        $this->assertNull($resolved['nothing']);

        $this->assertSame('facebook-ads', (new SubmissionContext($submission, ['fbclid' => 'x']))->source());
        $this->assertSame('www.bing.com', (new SubmissionContext($submission, ['referrer' => 'https://www.bing.com/search?q=kitchens']))->source());
        $this->assertSame('direct', (new SubmissionContext($submission))->source());
    }

    public function test_the_client_changes_the_mapping_for_one_form(): void
    {
        $form = $this->contact(['destinations' => ['leads'], 'destination_maps' => ['leads' => ['source' => '@source', 'message' => '', 'is_admin' => '1']]]);

        $this->postJson('/cms/forms/contact', $this->answers($form, ['_attribution' => ['gclid' => 'abc']]))->assertOk();

        $lead = Lead::query()->sole();
        $this->assertSame('google-ads', $lead->source);
        $this->assertNull($lead->message, 'A blank in the form\'s mapping leaves the field out.');
        $this->assertArrayNotHasKey('is_admin', $lead->getAttributes(), 'Only the destination\'s own fields can be mapped.');
    }

    public function test_a_destination_that_fails_is_reported_and_the_visitor_is_still_thanked(): void
    {
        Exceptions::fake();

        app(FormDestinations::class)->register(new class implements FormDestination
        {
            public function key(): string
            {
                return 'crm';
            }

            public function label(): string
            {
                return 'The CRM';
            }

            public function fields(): array
            {
                return [];
            }

            public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): mixed
            {
                throw new RuntimeException('The CRM is down.');
            }
        });

        $form = $this->contact(['destinations' => ['crm', 'leads']]);

        $this->postJson('/cms/forms/contact', $this->answers($form))->assertOk()->assertJson(['ok' => true]);

        $submission = FormSubmission::query()->sole();
        $this->assertFalse($submission->meta['destinations']['crm']['ok']);
        $this->assertSame('RuntimeException: The CRM is down.', $submission->meta['destinations']['crm']['error']);
        $this->assertTrue($submission->meta['destinations']['leads']['ok'], 'One destination failing never stops the next.');
        $this->assertSame(1, Lead::query()->count());
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The CRM is down.');

        $this->actingAs($this->editor());
        $this->assertStringContainsString('Not saved: RuntimeException: The CRM is down.', view('gadya-cms::filament.submissions.detail', ['submission' => $submission])->render());
    }

    public function test_a_destination_the_site_wrote_is_named_in_config(): void
    {
        config(['gadya-cms.forms.builder.destinations.notes' => NoteTheLead::class]);
        NoteTheLead::$seen = [];

        $form = $this->contact(['destinations' => ['notes']]);
        $this->postJson('/cms/forms/contact', $this->answers($form))->assertOk();

        $this->assertSame(['contact', 'aleksey', '2026-03'], NoteTheLead::$seen);
        $this->assertSame('noted', FormSubmission::query()->sole()->meta['destinations']['notes']['result']);
    }

    public function test_the_event_is_dispatched_for_built_and_configured_forms_alike(): void
    {
        Event::fake([FormSubmitted::class]);

        $form = $this->contact(['destinations' => []]);
        $this->postJson('/cms/forms/contact', $this->answers($form))->assertOk();

        config(['gadya-cms.forms.forms.enquiry' => ['label' => 'Enquiry', 'fields' => ['name' => ['required', 'string'], 'email' => ['required', 'email']]]]);
        $this->postJson('/cms/forms/enquiry', ['name' => 'Pat', 'email' => 'pat@example.com'], ['Referer' => 'http://localhost/about?utm_source=newsletter'])->assertOk();

        Event::assertDispatched(FormSubmitted::class, fn (FormSubmitted $event): bool => $event->form?->is($form) && $event->data['email'] === 'anna@example.com' && $event->context->site() === 'aleksey');
        Event::assertDispatched(FormSubmitted::class, fn (FormSubmitted $event): bool => $event->definition?->name === 'enquiry'
            && $event->formName() === 'enquiry'
            && $event->context->token('utm_source') === 'newsletter');
    }

    public function test_choosing_a_destination_in_the_panel_matches_its_fields_to_the_questions(): void
    {
        $form = $this->contact(['destinations' => []]);

        $page = Livewire::actingAs($this->editor())
            ->test(EditForm::class, ['record' => $form->getKey()])
            ->set('data.settings.destinations', ['leads']);

        $map = collect($page->get('data.settings.destination_maps.leads'))->pluck('value', 'key')->all();

        $this->assertSame('name', $map['name']);
        $this->assertSame('@utm_source', $map['source'], 'What the developer mapped in config is kept.');
        $this->assertSame('@consent.at', $map['consented_at']);
        $this->assertArrayNotHasKey('status', $map, 'A field set by a default is not offered.');

        $page->call('save')->assertHasNoFormErrors();

        $form->refresh();
        $this->assertSame(['leads'], $form->setting('destinations'));
        $this->assertSame('email', $form->setting('destination_maps.leads.email'));
    }

    public function test_a_mapping_is_suggested_from_names_kinds_and_tokens(): void
    {
        $schema = Form::factory()->make(['fields' => [
            ['type' => 'name', 'key' => 'your_name', 'label' => 'Name'],
            ['type' => 'email', 'key' => 'contact_email', 'label' => 'Email'],
            ['type' => 'phone', 'key' => 'tel', 'label' => 'Phone'],
            ['type' => 'long_text', 'key' => 'details', 'label' => 'Details'],
        ]])->schema();

        $this->assertSame([
            'name' => 'your_name',
            'email' => 'contact_email',
            'phone' => 'tel',
            'message' => 'details',
            'utm_campaign' => '@utm_campaign',
            'referrer' => '@referrer',
            'language' => '@locale',
            'privacy_policy_version' => '@consent.policy_version',
            'agreed_at' => '@consent.at',
        ], DestinationMap::suggest(['name', 'email', 'phone', 'message', 'utm_campaign', 'referrer', 'language', 'privacy_policy_version', 'agreed_at', 'favourite_colour'], $schema));
    }
}

/**
 * A destination a site writes itself, for what a mapping cannot say.
 */
class NoteTheLead implements FormDestination
{
    /** @var list<mixed> */
    public static array $seen = [];

    public function key(): string
    {
        return 'notes';
    }

    public function label(): string
    {
        return 'Notes';
    }

    public function fields(): array
    {
        return [];
    }

    public function handle(Form $form, FormSubmission $submission, array $data, SubmissionContext $context): mixed
    {
        self::$seen = [$form->slug, $context->site(), $context->token('@consent.policy_version')];

        return 'noted';
    }
}
