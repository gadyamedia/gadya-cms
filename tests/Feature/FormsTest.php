<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Filament\Resources\Submissions\Pages\ListSubmissions;
use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class FormsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        config(['gadya-cms.forms.forms.contact.notify' => ['owner@example.com']]);
        Notification::fake();
    }

    public function test_a_submission_is_kept_emailed_and_counted(): void
    {
        $this->from('/about')
            ->post('/cms/forms/contact', [
                'name' => 'Pat',
                'email' => 'pat@example.com',
                'message' => 'Can you do Saturday?',
                'colour' => 'not a configured field',
            ])
            ->assertRedirect('/about')
            ->assertSessionHas('gadya-cms.form.contact', 'Thank you. We will be in touch soon.');

        $submission = FormSubmission::query()->firstOrFail();

        $this->assertSame('contact', $submission->form);
        $this->assertSame('Pat', $submission->sender());
        $this->assertSame(['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Can you do Saturday?'], $submission->data);
        $this->assertSame('new', $submission->status);
        $this->assertSame('/about', $submission->path);

        Notification::assertSentOnDemand(FormSubmitted::class, fn (FormSubmitted $notification, array $channels, $notifiable): bool => in_array('owner@example.com', $notifiable->routes['mail'] ?? [], true)
            && $notification->submission->is($submission));

        $this->assertDatabaseHas('gadyacms_analytics_events', ['name' => 'lead_form_submit', 'path' => '/about']);
    }

    public function test_the_email_says_what_was_sent_and_replies_go_to_the_sender(): void
    {
        $submission = FormSubmission::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'form' => 'contact',
            'data' => ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hello there'],
            'created_at' => now(),
        ]);

        $mail = (new FormSubmitted($submission, FormDefinition::find('contact')))->toMail(new \stdClass);

        $this->assertStringContainsString('Pat', $mail->subject);
        $this->assertSame([['pat@example.com', 'Pat']], $mail->replyTo);
        $this->assertStringContainsString('Hello there', implode("\n", $mail->introLines));
    }

    public function test_validation_failures_go_back_to_the_form_with_its_own_error_bag(): void
    {
        $this->from('/about')
            ->post('/cms/forms/contact', ['name' => 'Pat', 'email' => 'not-an-email', 'message' => ''])
            ->assertRedirect('/about')
            ->assertSessionHasErrorsIn('gadya-cms.contact', ['email', 'message']);

        $this->assertDatabaseCount('gadyacms_form_submissions', 0);
        Notification::assertNothingSent();
    }

    public function test_a_bot_that_fills_the_honeypot_is_told_it_worked_and_ignored(): void
    {
        $this->post('/cms/forms/contact', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'message' => 'Buy things',
            'website' => 'https://spam.example',
        ])->assertRedirect();

        $this->assertDatabaseCount('gadyacms_form_submissions', 0);
        $this->assertDatabaseCount('gadyacms_analytics_events', 0);
    }

    public function test_an_unknown_form_is_not_found_and_a_script_gets_json(): void
    {
        $this->post('/cms/forms/newsletter', ['email' => 'a@b.c'])->assertNotFound();

        $this->postJson('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->postJson('/cms/forms/contact', ['name' => 'Pat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'message']);
    }

    public function test_a_redirect_after_sending_must_stay_on_this_site(): void
    {
        $this->post('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi', '_redirect' => '/thanks'])
            ->assertRedirect('/thanks');

        $this->from('/about')
            ->post('/cms/forms/contact', ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Hi', '_redirect' => 'https://evil.example'])
            ->assertRedirect('/about');
    }

    public function test_the_blade_directives_render_the_hidden_fields_and_the_status(): void
    {
        $fields = Blade::render("@cmsForm('contact')");

        $this->assertStringContainsString('name="_token"', $fields);
        $this->assertStringContainsString('name="website"', $fields);
        $this->assertStringContainsString('name="_path"', $fields);

        session()->flash('gadya-cms.form.contact', 'Sent!');
        $status = Blade::render("@cmsFormStatus('contact')");

        $this->assertStringContainsString('Sent!', $status);
    }

    public function test_opening_an_enquiry_in_the_panel_marks_it_read_and_the_badge_counts_the_rest(): void
    {
        $siteId = app(SiteContext::class)->id();
        $first = FormSubmission::query()->create(['site_id' => $siteId, 'form' => 'contact', 'data' => ['name' => 'First', 'message' => 'One'], 'created_at' => now()]);
        FormSubmission::query()->create(['site_id' => $siteId, 'form' => 'contact', 'data' => ['name' => 'Second', 'message' => 'Two'], 'created_at' => now()]);

        $this->actingAs($this->editor());
        $this->assertSame('2', SubmissionResource::getNavigationBadge());

        Livewire::actingAs($this->editor())
            ->test(ListSubmissions::class)
            ->assertCanSeeTableRecords(FormSubmission::all())
            ->mountAction(TestAction::make('open')->table($first));

        $this->assertStringContainsString('One', view('gadya-cms::filament.submissions.detail', ['submission' => $first])->render());

        $this->assertSame('read', $first->fresh()->status);
        $this->assertSame('1', SubmissionResource::getNavigationBadge());
    }

    public function test_enquiries_can_be_downloaded_as_a_spreadsheet(): void
    {
        $siteId = app(SiteContext::class)->id();
        $record = FormSubmission::query()->create(['site_id' => $siteId, 'form' => 'contact', 'data' => ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Saturday?'], 'created_at' => now()]);

        $response = Livewire::actingAs($this->editor())
            ->test(ListSubmissions::class)
            ->selectTableRecords([$record])
            ->callAction(TestAction::make('export')->table()->bulk())
            ->assertFileDownloaded();

        $this->assertTrue(true);
    }
}
