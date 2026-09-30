<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Ai\Agents\FormWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Filament\Resources\Forms\Pages\CreateForm;
use Gadya\Cms\Filament\Resources\Forms\Pages\EditForm;
use Gadya\Cms\Filament\Resources\Forms\Pages\ListForms;
use Gadya\Cms\Forms\Builder\ConvertConfigForm;
use Gadya\Cms\Forms\Builder\FormSchemaValidator;
use Gadya\Cms\Forms\Builder\FormTemplates;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Tests\Fixtures\User;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Content → Forms in the panel: building, previewing, copying, putting
 * away, starting from a template or a description, and taking over a
 * form from the site's code.
 */
class FormResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    public function test_an_editor_builds_a_form_and_its_questions_are_stored_flat(): void
    {
        Livewire::actingAs($this->editor())
            ->test(CreateForm::class)
            ->fillForm([
                'title' => 'Party booking',
                'slug' => 'party-booking',
                'status' => Form::STATUS_PUBLISHED,
                'fields' => [
                    ['type' => 'short_text', 'data' => ['label' => 'Child\'s name', 'required' => true]],
                    ['type' => 'radio', 'data' => ['label' => 'Package', 'options' => [['label' => 'Bronze'], ['label' => 'Gold']]]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $form = Form::query()->where('slug', 'party-booking')->sole();

        $this->assertSame(['child_s_name', 'package'], array_column($form->fields, 'key'));
        $this->assertSame(['bronze', 'gold'], array_column($form->fields[1]['options'], 'key'));
        $this->assertNotNull($form->published_at);
        $this->assertSame(1, $form->versions()->count());
    }

    public function test_the_preview_sits_in_a_frame_so_its_required_questions_cannot_stop_the_save_button(): void
    {
        $form = Form::factory()->published()->create(['slug' => 'contact-us']);
        $form->recordVersion();

        $html = Livewire::actingAs($this->editor())
            ->test(EditForm::class, ['record' => $form->getKey()])
            ->assertSeeHtml('sandbox="allow-scripts"')
            ->html();

        /*
         * Were the preview's inputs part of this page, an empty required
         * one - on a tab nobody can see - would make the browser refuse
         * the admin's own form, and "Save changes" would do nothing.
         */
        $outsideTheFrame = (string) preg_replace('/srcdoc="[^"]*"/s', '', $html);

        $this->assertStringContainsString('srcdoc="', $html);
        $this->assertStringNotContainsString('cms-bform', $outsideTheFrame);
    }

    public function test_a_form_nobody_could_fill_in_is_not_saved(): void
    {
        Livewire::actingAs($this->editor())
            ->test(CreateForm::class)
            ->fillForm([
                'title' => 'Empty',
                'slug' => 'empty',
                'status' => Form::STATUS_DRAFT,
                'fields' => [['type' => 'heading', 'data' => ['label' => 'Just a heading']]],
            ])
            ->call('create')
            ->assertNotified('The form cannot be saved yet');

        $this->assertDatabaseCount('gadyacms_forms', 0);
    }

    public function test_editing_keeps_the_keys_shows_a_preview_and_numbers_the_version(): void
    {
        $form = Form::factory()->published()->create(['slug' => 'contact-us']);
        $form->recordVersion();

        $page = Livewire::actingAs($this->editor())
            ->test(EditForm::class, ['record' => $form->getKey()])
            ->assertSee('cms-bform', false)
            ->assertSee('How can we help?');

        $fields = $page->get('data.fields');
        $first = array_key_first($fields);
        $fields[$first]['data']['label'] = 'Full name';

        $page->set('data.fields', $fields)->call('save')->assertHasNoFormErrors();

        $form->refresh();
        $this->assertSame('Full name', $form->fields[0]['label']);
        $this->assertSame('name', $form->fields[0]['key'], 'Renaming a question never changes where its answers are kept.');
        $this->assertSame(2, $form->version);
        $this->assertSame(2, $form->versions()->count());
    }

    public function test_duplicating_and_archiving(): void
    {
        $form = Form::factory()->published()->create(['slug' => 'quote', 'title' => 'Quote']);

        Livewire::actingAs($this->editor())
            ->test(ListForms::class)
            ->assertCanSeeTableRecords([$form])
            ->callAction(TestAction::make('duplicate')->table($form));

        $copy = Form::query()->where('slug', 'quote-copy')->sole();
        $this->assertSame(Form::STATUS_DRAFT, $copy->status);
        $this->assertSame('Quote (copy)', $copy->title);

        Livewire::actingAs($this->editor())
            ->test(ListForms::class)
            ->callAction(TestAction::make('archive')->table($form));

        $this->assertSame(Form::STATUS_ARCHIVED, $form->fresh()->status);
        $this->assertNull(Form::findLive('quote'));
    }

    public function test_every_template_makes_a_form_that_can_be_filled_in(): void
    {
        $templates = app(FormTemplates::class);

        $this->assertGreaterThanOrEqual(9, count($templates->all()));

        foreach (array_keys($templates->all()) as $key) {
            $form = $templates->create($key);

            $this->assertSame([], app(FormSchemaValidator::class)->errors($form->fields), "The {$key} template is broken.");
        }

        $this->assertStringContainsString(config('gadya-cms.brand.name'), Form::query()->where('template', 'callback')->sole()->fields[4]['label']);

        Livewire::actingAs($this->editor())
            ->test(ListForms::class)
            ->callAction('fromTemplate', ['template' => 'catering'])
            ->assertRedirect();

        $this->assertCount(3, Form::query()->where('template', 'catering')->latest('id')->first()->schema()->steps());
    }

    public function test_describing_a_form_drafts_one_with_the_sites_ai(): void
    {
        app(AiSettings::class)->save(['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'key' => 'sk-ant-123']);

        FormWriter::fake([[
            'title' => 'Catering order',
            'fields' => [
                ['type' => 'name', 'label' => 'Your name', 'required' => true],
                ['type' => 'number', 'label' => 'How many guests?', 'required' => true],
                ['type' => 'checkboxes', 'label' => 'Dietary needs', 'required' => false, 'options' => ['Vegan', 'Gluten-free']],
                ['type' => 'hologram', 'label' => 'Dropped', 'required' => false],
                ['type' => 'select', 'label' => 'No choices, dropped', 'required' => false, 'options' => []],
            ],
            'success' => 'Thanks - we will confirm by email.',
        ]]);

        Livewire::actingAs($this->editor())
            ->test(ListForms::class)
            ->callAction('describe', ['description' => 'Catering orders with headcount and allergies'])
            ->assertRedirect();

        $form = Form::query()->sole();

        $this->assertSame('Catering order', $form->title);
        $this->assertSame(Form::STATUS_DRAFT, $form->status);
        $this->assertSame(['name', 'number', 'checkboxes'], array_column($form->fields, 'type'));
        $this->assertSame('Thanks - we will confirm by email.', $form->message('success'));

        FormWriter::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'headcount'));
    }

    public function test_a_configured_form_is_copied_into_the_builder_and_takes_over_only_when_published(): void
    {
        Notification::fake();
        app(Options::class)->set('forms.replies', ['contact' => ['enabled' => true, 'subject' => 'Thanks {{ name }}', 'body' => 'Hi {{ name }}']]);
        config(['gadya-cms.forms.forms.contact.notify' => ['owner@example.com']]);

        Livewire::actingAs($this->editor())
            ->test(ListForms::class)
            ->callAction('convert', ['form' => 'contact'])
            ->assertRedirect();

        $form = Form::query()->where('slug', 'contact')->sole();

        $this->assertSame(['name', 'email', 'phone', 'message'], array_column($form->fields, 'key'));
        $this->assertSame(['short_text', 'email', 'phone', 'long_text'], array_column($form->fields, 'type'));
        $this->assertSame(['owner@example.com'], $form->setting('notify'));
        $this->assertSame('Thanks {name}', $form->setting('autoreply.subject'));
        $this->assertTrue(app(ConvertConfigForm::class)->candidates()['contact']['converted']);

        /* A draft: the configured form still answers, hand-written HTML and all. */
        $this->postJson('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi'])->assertOk();
        $this->assertNull(FormSubmission::query()->sole()->form_id);

        $form->update(['status' => Form::STATUS_PUBLISHED]);

        $this->postJson('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi', '_t' => app(SpamGuard::class)->seal('contact')])->assertOk();
        /* Sealed a moment ago: too fast, so a bot. */
        $this->assertDatabaseCount('gadyacms_form_submissions', 1);

        $this->postJson('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi'])->assertOk();
        $this->assertSame($form->getKey(), FormSubmission::query()->latest('id')->first()->form_id);
        $this->assertSame('contact', FormSubmission::query()->latest('id')->first()->form);
    }

    public function test_an_editor_may_build_forms(): void
    {
        $this->actingAs($this->editor())->get(FormResource::getUrl())->assertOk();
    }

    public function test_someone_who_only_writes_articles_may_not(): void
    {
        config(['gadya-cms.users.roles.contributor' => ['label' => 'Contributor', 'abilities' => ['articles']]]);
        $contributor = User::query()->create(['name' => 'Cal', 'email' => 'cal@example.com', 'password' => 'password', 'role' => 'contributor']);

        $this->actingAs($contributor)->get(FormResource::getUrl())->assertForbidden();
    }

    public function test_the_share_screen_gives_every_way_to_place_it(): void
    {
        $form = Form::factory()->published()->create(['slug' => 'rsvp', 'title' => 'RSVP']);

        $html = view('gadya-cms::filament.forms.share', ['form' => $form])->render();

        $this->assertStringContainsString('[form:rsvp]', $html);
        $this->assertStringContainsString('/forms/rsvp/embed', $html);
        $this->assertStringContainsString('&lt;x-gadya-cms::form form=&quot;rsvp&quot; /&gt;', e('<x-gadya-cms::form form="rsvp" />'));
        $this->assertStringContainsString('@cmsFormEmbed', $html);
    }
}
