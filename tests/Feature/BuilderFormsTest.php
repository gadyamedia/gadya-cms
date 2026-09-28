<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\Builder\FieldType;
use Gadya\Cms\Forms\Builder\FieldTypes;
use Gadya\Cms\Forms\Builder\FormSchema;
use Gadya\Cms\Forms\Builder\FormSchemaValidator;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormDraft;
use Gadya\Cms\Models\FormEvent;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Notifications\FormResumeLink;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Forms built in the panel: what the builder may save, how a form is
 * drawn, and what happens to what a visitor sends through one.
 */
class BuilderFormsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    private function catering(array $settings = []): Form
    {
        return Form::factory()->published()->withSettings($settings)->withFields([
            ['type' => 'name', 'key' => 'name', 'label' => 'Your name', 'required' => true],
            ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
            ['type' => 'select', 'key' => 'service', 'label' => 'What do you need?', 'required' => true, 'options' => [
                ['key' => 'party', 'label' => 'A party'],
                ['key' => 'catering', 'label' => 'Catering'],
            ]],
            ['type' => 'number', 'key' => 'guests', 'label' => 'How many guests?', 'required' => true, 'rules' => ['min' => 10],
                'logic' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'service', 'operator' => 'equals', 'value' => 'Catering']]]],
            ['type' => 'checkbox', 'key' => 'vegetarian', 'label' => 'Vegetarian options'],
            ['type' => 'currency', 'key' => 'budget', 'label' => 'Budget'],
        ])->create(['slug' => 'catering', 'title' => 'Catering order']);
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

        return ['_t' => $seal, '_path' => '/contact', ...$answers];
    }

    public function test_the_builder_refuses_a_form_that_could_not_be_filled_in(): void
    {
        $validator = app(FormSchemaValidator::class);

        $this->assertContains('Add at least one question for people to answer.', $validator->errors([['type' => 'heading', 'label' => 'Hello']]));

        $errors = $validator->errors([
            ['type' => 'select', 'label' => 'Service', 'options' => []],
            ['type' => 'short_text', 'label' => 'Code', 'rules' => ['pattern' => '([a-z']],
            ['type' => 'short_text', 'label' => 'Depends', 'logic' => ['action' => 'show', 'rules' => [['field' => 'later', 'operator' => 'equals', 'value' => 'x']]]],
            ['type' => 'short_text', 'key' => 'later', 'label' => 'Later'],
            ['type' => 'hologram', 'label' => 'Unknown'],
            ['type' => 'number', 'label' => 'Size', 'rules' => ['min' => 10, 'max' => 2]],
        ]);

        $this->assertContains('"Service" needs at least one choice.', $errors);
        $this->assertContains('"Code" has a "must match" pattern that is not a valid regular expression.', $errors);
        $this->assertContains('"Depends" depends on a question that is not above it ("later").', $errors);
        $this->assertContains('"Unknown" is a kind of field this site does not know (hologram).', $errors);
        $this->assertContains('"Size": the smallest allowed is more than the largest.', $errors);

        $this->assertSame([], $validator->errors($this->catering()->fields));
    }

    public function test_every_field_and_choice_gets_a_lasting_key_and_the_forms_own_names_are_never_taken(): void
    {
        $fields = FormSchema::normalise([
            ['type' => 'short_text', 'label' => 'Your name'],
            ['type' => 'short_text', 'label' => 'Your name'],
            ['type' => 'short_text', 'label' => 'Website'],
            ['type' => 'radio', 'label' => 'Size', 'options' => ['Small', 'Large', ['key' => 'xl', 'label' => 'Extra large']]],
            ['type' => 'page_break', 'data' => ['label' => 'Step two']],
        ]);

        $this->assertSame(['your_name', 'your_name_2', 'website_field', 'size', 'step_two'], array_column($fields, 'key'));
        $this->assertSame(['small', 'large', 'xl'], array_column($fields[3]['options'], 'key'));
        $this->assertSame('Step two', $fields[4]['label']);
        $this->assertGreaterThanOrEqual(25, count(app(FieldTypes::class)->all()));
    }

    public function test_a_form_is_drawn_with_labels_legends_and_the_hidden_machinery(): void
    {
        $form = Form::factory()->published()->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Your name', 'required' => true, 'help' => 'As on your ID'],
            ['type' => 'radio', 'key' => 'size', 'label' => 'Party size', 'options' => ['Small', 'Large']],
            ['type' => 'hidden', 'key' => 'campaign'],
            ['type' => 'rating', 'key' => 'stars', 'label' => 'How did we do?'],
        ])->create(['slug' => 'party']);

        $this->get('/about?campaign=spring-flyer');
        $html = Blade::render('<x-gadya-cms::form form="party" />');

        $this->assertStringContainsString('<label class="cms-bform__label" for="cms-form-party-name"', $html);
        $this->assertStringContainsString('aria-describedby="cms-form-party-name-help cms-form-party-name-error"', $html);
        $this->assertStringContainsString('<legend class="cms-bform__label" id="cms-form-party-size">Party size</legend>', $html);
        $this->assertStringContainsString('name="website"', $html);
        $this->assertStringContainsString('name="_t"', $html);
        $this->assertStringContainsString('3 stars', $html);
        $this->assertStringContainsString('window.gadyaForms', $html, 'The script comes with the first form on the page.');

        $this->assertSame('', trim(Blade::render('<x-gadya-cms::form form="no-such-form" />')), 'A visitor sees nothing where no form is published.');

        $form->update(['status' => Form::STATUS_DRAFT]);
        $this->assertSame('', trim(Blade::render('@cmsFormEmbed(\'party\')')));
    }

    public function test_a_hidden_field_takes_its_value_from_the_address(): void
    {
        Form::factory()->published()->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Name'],
            ['type' => 'hidden', 'key' => 'campaign'],
        ])->create(['slug' => 'party']);

        $html = $this->get(route('gadya-cms.forms.page', 'party').'?campaign=spring-flyer')->assertOk()->getContent();

        $this->assertStringContainsString('name="campaign" value="spring-flyer"', $html);
    }

    public function test_a_sent_form_is_kept_in_the_inbox_in_the_sites_own_words(): void
    {
        $form = $this->catering();

        $this->from('/contact')->post('/cms/forms/catering', $this->answers($form, [
            'name' => ['first' => 'Pat', 'last' => 'Jones'],
            'email' => 'PAT@example.com',
            'service' => 'catering',
            'guests' => '40',
            'vegetarian' => '1',
            'budget' => '1500',
            'not_a_question' => 'dropped',
        ]))->assertRedirect('/contact')->assertSessionHas('gadya-cms.form.catering', 'Thank you. We will be in touch soon.');

        $submission = FormSubmission::query()->sole();

        $this->assertSame('catering', $submission->form);
        $this->assertSame($form->getKey(), $submission->form_id);
        $this->assertSame([
            'name' => 'Pat Jones',
            'email' => 'pat@example.com',
            'service' => 'Catering',
            'guests' => '40',
            'vegetarian' => 'Yes',
            'budget' => '1500.00',
        ], $submission->data);
        $this->assertSame('Pat Jones', $submission->sender());
        $this->assertSame('name', $submission->fieldTypes()['name']);
        $this->assertSame('How many guests?', $submission->fieldLabels()['guests']);
        $this->assertSame(10, $submission->meta['duration_seconds']);

        $this->assertDatabaseHas('gadyacms_analytics_events', ['name' => 'lead_form_submit', 'path' => '/contact']);
        $this->assertSame(1, FormEvent::query()->where('name', FormEvent::COMPLETE)->count());
    }

    public function test_a_hidden_question_is_never_required_and_its_answer_is_dropped(): void
    {
        $form = $this->catering();

        $this->postJson('/cms/forms/catering', $this->answers($form, [
            'name' => ['first' => 'Pat', 'last' => 'Jones'],
            'email' => 'pat@example.com',
            'service' => 'party',
            'guests' => '3',
        ]))->assertOk()->assertJson(['ok' => true]);

        $this->assertArrayNotHasKey('guests', FormSubmission::query()->sole()->data, 'Hidden by its rule, so never kept - even though it was posted.');

        $this->postJson('/cms/forms/catering', $this->answers($form, [
            'name' => ['first' => 'Pat', 'last' => 'Jones'],
            'email' => 'pat@example.com',
            'service' => 'catering',
        ]))->assertStatus(422)->assertJsonValidationErrors(['guests']);
    }

    public function test_a_step_is_checked_on_its_own_and_a_hidden_step_is_skipped(): void
    {
        $form = Form::factory()->published()->withFields([
            ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
            ['type' => 'yes_no', 'key' => 'delivery', 'label' => 'Should we deliver?', 'required' => true],
            ['type' => 'page_break', 'label' => 'Delivery', 'logic' => ['action' => 'show', 'rules' => [['field' => 'delivery', 'operator' => 'equals', 'value' => 'yes']]]],
            ['type' => 'address', 'key' => 'address', 'label' => 'Where to?', 'required' => true],
            ['type' => 'page_break', 'label' => 'Anything else'],
            ['type' => 'long_text', 'key' => 'notes', 'label' => 'Notes'],
        ])->create(['slug' => 'order']);

        $this->assertCount(3, $form->schema()->steps());

        $this->postJson('/cms/forms/order', ['_validate_step' => 0, 'email' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'delivery'])
            ->assertJsonMissingValidationErrors(['address.line1']);

        $this->postJson('/cms/forms/order', ['_validate_step' => 0, 'email' => 'pat@example.com', 'delivery' => 'no'])->assertOk();

        $this->postJson('/cms/forms/order', $this->answers($form, ['email' => 'pat@example.com', 'delivery' => 'no', 'notes' => 'Collecting.']))->assertOk();
        $this->assertSame(['email' => 'pat@example.com', 'delivery' => 'No', 'notes' => 'Collecting.'], FormSubmission::query()->sole()->data);

        $this->postJson('/cms/forms/order', $this->answers($form, ['email' => 'pat@example.com', 'delivery' => 'yes']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address.line1', 'address.zip']);

        $this->postJson('/cms/forms/order', $this->answers($form, [
            'email' => 'pat@example.com',
            'delivery' => 'yes',
            'address' => ['line1' => '1 Main St', 'city' => 'Newark', 'state' => 'NJ', 'zip' => '07102'],
        ]))->assertOk();

        $this->assertSame('1 Main St, Newark, NJ 07102', FormSubmission::query()->latest('id')->first()->data['address']);
    }

    public function test_without_javascript_errors_come_back_to_the_form_with_what_was_typed(): void
    {
        $form = $this->catering();

        $this->from('/contact')->post('/cms/forms/catering', $this->answers($form, [
            'name' => ['first' => 'Pat', 'last' => ''],
            'email' => 'not-an-email',
            'service' => 'catering',
        ]))->assertRedirect('/contact')->assertSessionHasErrorsIn('gadya-cms.catering', ['email', 'guests', 'name.last']);

        $html = Blade::render('<x-gadya-cms::form form="catering" />');

        $this->assertStringContainsString('value="Pat"', $html);
        $this->assertStringContainsString('cms-bform__field--invalid', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringNotContainsString('name="website" value', $html);
    }

    public function test_a_bot_is_thanked_and_ignored_but_hand_written_html_still_works(): void
    {
        $form = $this->catering();
        $answers = ['name' => ['first' => 'Pat', 'last' => 'Jones'], 'email' => 'pat@example.com', 'service' => 'party'];

        $this->postJson('/cms/forms/catering', [...$this->answers($form, $answers), 'website' => 'spam'])->assertOk();
        $this->postJson('/cms/forms/catering', [...$answers, '_t' => app(SpamGuard::class)->seal('catering')])->assertOk();
        $this->postJson('/cms/forms/catering', [...$answers, '_t' => 'forged'])->assertOk();

        $this->assertDatabaseCount('gadyacms_form_submissions', 0);

        $this->postJson('/cms/forms/catering', $answers)->assertOk();
        $this->assertDatabaseCount('gadyacms_form_submissions', 1);
    }

    public function test_turnstile_is_asked_when_the_form_uses_it(): void
    {
        app(Options::class)->set('forms.turnstile.site_key', 'site-key');
        app(Options::class)->setSecret('forms.turnstile.secret', 'secret-key');
        $form = $this->catering(['turnstile' => true]);
        $answers = $this->answers($form, ['name' => ['first' => 'Pat', 'last' => 'Jones'], 'email' => 'pat@example.com', 'service' => 'party']);

        $this->assertStringContainsString('data-sitekey="site-key"', Blade::render('<x-gadya-cms::form form="catering" />'));

        Http::fake([SpamGuard::TURNSTILE_VERIFY => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->postJson('/cms/forms/catering', [...$answers, 'cf-turnstile-response' => 'bad'])->assertStatus(422)->assertJsonValidationErrors(['cf-turnstile-response']);
        $this->postJson('/cms/forms/catering', [...$answers, 'cf-turnstile-response' => 'good'])->assertOk();

        Http::assertSent(fn ($request): bool => $request['secret'] === 'secret-key' && $request['response'] === 'good');
        $this->assertDatabaseCount('gadyacms_form_submissions', 1);
    }

    public function test_uploads_are_kept_privately_and_opened_only_through_a_signed_link(): void
    {
        Storage::fake('local');
        $form = Form::factory()->published()->withFields([
            ['type' => 'email', 'key' => 'email', 'label' => 'Email', 'required' => true],
            ['type' => 'file', 'key' => 'plans', 'label' => 'Your plans', 'rules' => ['accept' => ['pdf'], 'multiple' => true]],
            ['type' => 'signature', 'key' => 'signature', 'label' => 'Sign here'],
        ])->create(['slug' => 'quote']);

        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\nfake");

        $this->postJson('/cms/forms/quote', $this->answers($form, ['email' => 'pat@example.com', 'plans' => [UploadedFile::fake()->create('kitchen.exe', 10)]]))
            ->assertStatus(422)->assertJsonValidationErrors(['plans.0']);

        $this->post('/cms/forms/quote', $this->answers($form, [
            'email' => 'pat@example.com',
            'plans' => [UploadedFile::fake()->create('kitchen.pdf', 20, 'application/pdf'), UploadedFile::fake()->create('bath.pdf', 20, 'application/pdf')],
            'signature' => $png,
        ]))->assertRedirect();

        $submission = FormSubmission::query()->sole();

        $this->assertSame('kitchen.pdf, bath.pdf', $submission->data['plans']);
        $this->assertSame('Signed (the signature is attached)', $submission->data['signature']);
        Storage::disk('local')->assertExists($submission->files['plans'][0]['path']);
        Storage::disk('local')->assertExists($submission->files['signature'][0]['path']);

        $url = URL::temporarySignedRoute('gadya-cms.forms.file', now()->addMinutes(30), ['submission' => $submission->getKey(), 'field' => 'plans', 'index' => 1]);

        $this->get($url)->assertForbidden();
        $this->actingAs($this->visitor())->get($url)->assertForbidden();
        $this->actingAs($this->editor())->get(route('gadya-cms.forms.file', ['submission' => $submission->getKey(), 'field' => 'plans', 'index' => 1]))->assertForbidden();
        $this->get($url)->assertOk()->assertDownload('bath.pdf');
    }

    public function test_someone_can_finish_later_from_a_link_in_their_email(): void
    {
        $form = $this->catering(['save_later' => true]);

        $this->from('/contact')->post(route('gadya-cms.forms.save', 'catering'), [
            '_path' => '/contact',
            '_step' => 0,
            '_resume_email' => 'pat@example.com',
            'email' => 'pat@example.com',
            'service' => 'catering',
        ])->assertRedirect('/contact')->assertSessionHas('gadya-cms.form-saved.catering');

        $draft = FormDraft::query()->sole();
        $this->assertSame('catering', $draft->data['service']);

        $link = null;
        Notification::assertSentOnDemand(FormResumeLink::class, function (FormResumeLink $notification) use (&$link): bool {
            $link = $notification->url;

            return true;
        });

        $this->get($link.'x')->assertForbidden();
        $this->get($link)->assertRedirect('/contact#cms-form-catering');

        $html = Blade::render('<x-gadya-cms::form form="catering" />');
        $this->assertStringContainsString('<option value="catering" selected', $html);
        $this->assertMatchesRegularExpression('/name="_resume" value="[A-Za-z0-9]+"/', $html);

        preg_match('/name="_resume" value="([A-Za-z0-9]+)"/', $html, $token);

        $this->postJson('/cms/forms/catering', $this->answers($form, [
            '_resume' => $token[1],
            'name' => ['first' => 'Pat', 'last' => 'Jones'],
            'email' => 'pat@example.com',
            'service' => 'party',
        ]))->assertOk();

        $this->assertDatabaseCount('gadyacms_form_drafts', 0);
        $this->get($link)->assertStatus(410);

        $this->travel(8)->days();
        $this->get(URL::temporarySignedRoute('gadya-cms.forms.resume', now()->subDay(), ['slug' => 'catering', 'token' => 'x']))->assertForbidden();
    }

    public function test_the_forms_own_page_and_the_iframe_for_other_sites(): void
    {
        $form = $this->catering();

        $this->get('/forms/catering')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('Catering order');

        $this->get('/forms/catering/embed')->assertOk()->assertHeader('Content-Security-Policy', 'frame-ancestors *')->assertDontSee('name="_token"', false);

        $this->post('/forms/catering/embed', $this->answers($form, ['email' => 'pat@example.com']))
            ->assertStatus(422)
            ->assertSee('cms-bform__field--invalid', false)
            ->assertSee('value="pat@example.com"', false);

        $this->post('/forms/catering/embed', $this->answers($form, ['name' => ['first' => 'Pat', 'last' => 'Jones'], 'email' => 'pat@example.com', 'service' => 'party']))
            ->assertOk()
            ->assertSee('Thank you. We will be in touch soon.');

        $this->assertDatabaseCount('gadyacms_form_submissions', 1);

        $form->update(['settings' => ['public_page' => false]]);
        $this->get('/forms/catering')->assertNotFound();
        $this->get('/forms/nothing')->assertNotFound();
    }

    public function test_ticking_the_mailing_list_box_joins_the_list(): void
    {
        $form = Form::factory()->published()->withFields([
            ['type' => 'name', 'key' => 'name', 'label' => 'Name'],
            ['type' => 'email', 'key' => 'your_email', 'label' => 'Email', 'required' => true],
            ['type' => 'mailing_list', 'key' => 'news', 'label' => 'Send me news'],
        ])->create(['slug' => 'rsvp', 'title' => 'RSVP']);

        $this->postJson('/cms/forms/rsvp', $this->answers($form, ['your_email' => 'no@example.com']))->assertOk();
        $this->postJson('/cms/forms/rsvp', $this->answers($form, ['name' => ['first' => 'Sam', 'last' => 'Lee'], 'your_email' => 'Sam@Example.com', 'news' => '1']))->assertOk();

        $subscriber = Subscriber::query()->sole();
        $this->assertSame('sam@example.com', $subscriber->email);
        $this->assertSame('Sam Lee', $subscriber->name);
        $this->assertSame('Form: RSVP', $subscriber->source);
    }

    public function test_a_site_can_add_a_kind_of_field_of_its_own(): void
    {
        app(FieldTypes::class)->register(FieldType::make('vin', 'Vehicle VIN')->group('Text')
            ->rules(fn (): array => ['' => ['string', 'size:17']])
            ->normaliseUsing(fn (mixed $value): string => strtoupper((string) $value)));

        $form = Form::factory()->published()->withFields([['type' => 'vin', 'key' => 'vin', 'label' => 'VIN', 'required' => true]])->create(['slug' => 'service']);

        $this->assertSame([], app(FormSchemaValidator::class)->errors($form->fields));
        $this->postJson('/cms/forms/service', $this->answers($form, ['vin' => 'short']))->assertStatus(422);
        $this->postJson('/cms/forms/service', $this->answers($form, ['vin' => '1hgcm82633a004352']))->assertOk();

        $this->assertSame('1HGCM82633A004352', FormSubmission::query()->sole()->data['vin']);
    }

    public function test_a_popup_opens_the_form_in_a_dialog(): void
    {
        $this->catering();

        $html = Blade::render('<x-gadya-cms::form-popup form="catering" button="Order catering" />');

        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertStringContainsString('<dialog', $html);
        $this->assertStringContainsString('Order catering', $html);
        $this->assertStringContainsString('data-cms-bform', $html);
    }
}
