<?php

namespace Gadya\Cms\Tests\Feature;

use Filament\Actions\Testing\TestAction;
use Gadya\Cms\Events\FormSubmitted as FormSubmittedEvent;
use Gadya\Cms\Filament\Pages\Emails;
use Gadya\Cms\Filament\Resources\Forms\Pages\CreateForm;
use Gadya\Cms\Forms\Builder\FormTestSender;
use Gadya\Cms\Mail\SenderPanel;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Sms\TwilioSettings;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * "Send a test" on a form's emails and texts: the real notification, from
 * sample answers, marked as a test - and nothing kept - with a plain
 * account of who sends it and what happened.
 */
class FormTestSendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    /**
     * A form that has not been saved, as it stands on screen.
     *
     * @param  array<string, mixed>  $settings
     */
    private function form(array $settings = []): Form
    {
        return Form::factory()->withSettings($settings)->withFields([
            ['type' => 'name', 'key' => 'your_name', 'label' => 'Your name', 'required' => true],
            ['type' => 'email', 'key' => 'your_email', 'label' => 'Email', 'required' => true],
            ['type' => 'select', 'key' => 'service', 'label' => 'Service', 'options' => [['key' => 'party', 'label' => 'Party'], ['key' => 'catering', 'label' => 'Catering']]],
            ['type' => 'long_text', 'key' => 'details', 'label' => 'Details'],
        ])->make(['slug' => 'enquiry', 'title' => 'Enquiry']);
    }

    private function pair(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh', 'site_name' => 'Acme Dental']);
        config(['gadya-cms.mail.shared' => true, 'gadya-cms.seo.site_name' => 'Acme Dental']);
        app()->forgetInstance(SharedSender::class);
    }

    private function twilio(): void
    {
        app(TwilioSettings::class)->save(['sid' => 'AC'.str_repeat('a', 32), 'token' => 'secret-token', 'from' => '(732) 555-0100']);
    }

    public function test_a_test_is_the_real_email_marked_as_a_test_and_keeps_nothing(): void
    {
        Notification::fake();
        Event::fake([FormSubmittedEvent::class]);
        Queue::fake();
        Http::fake();

        $report = app(FormTestSender::class)->built($this->form([
            'notify' => ['owner@example.com', 'second@example.com'],
            'email_subject' => '{service} request from {name}',
            'email_body' => 'A new one for {business}.',
        ]), []);

        $this->assertTrue($report->succeeded());

        Notification::assertSentOnDemand(FormSubmitted::class, function (FormSubmitted $notification, array $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === ['owner@example.com', 'second@example.com']
                && $mail->subject === '[Test] Party request from Sam Sample'
                && $mail->greeting === FormSubmitted::testBanner()
                && str_contains(implode("\n", $mail->introLines), 'A new one for '.config('gadya-cms.brand.name'))
                && $mail->replyTo === [['sam.sample@example.com', 'Sam Sample']];
        });

        $this->assertSame(0, FormSubmission::query()->count());
        Event::assertNotDispatched(FormSubmittedEvent::class);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_only_me_and_this_address_replace_the_list_and_nobody_else_is_emailed(): void
    {
        Notification::fake();
        $sender = app(FormTestSender::class);
        $form = $this->form(['notify' => ['owner@example.com']]);

        $sender->built($form, ['to' => 'me', 'me' => 'Ada@Example.com']);
        $sender->built($form, ['to' => 'address', 'address' => 'someone@else.test']);

        Notification::assertSentOnDemandTimes(FormSubmitted::class, 2);
        Notification::assertSentOnDemand(FormSubmitted::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['ada@example.com']);
        Notification::assertSentOnDemand(FormSubmitted::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['someone@else.test']);
    }

    public function test_an_empty_list_says_so_instead_of_pretending(): void
    {
        Notification::fake();

        $report = app(FormTestSender::class)->built($this->form(), []);

        $this->assertFalse($report->succeeded());
        $this->assertStringContainsString('nobody to send it to', $report->body());
        Notification::assertNothingSent();
    }

    public function test_a_routing_rule_can_be_simulated_and_is_named(): void
    {
        Notification::fake();
        $form = $this->form([
            'notify' => ['owner@example.com'],
            'routes' => [
                ['field' => 'details', 'operator' => 'contains', 'value' => 'wedding', 'emails' => ['weddings@example.com']],
                ['field' => 'service', 'operator' => 'equals', 'value' => 'Catering', 'emails' => ['catering@example.com'], 'instead' => true],
            ],
        ]);

        $report = app(FormTestSender::class)->built($form, ['rule' => 1]);

        Notification::assertSentOnDemand(FormSubmitted::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['catering@example.com']);
        $this->assertStringContainsString('the rule that fired: "Service is Catering"', $report->body());

        $report = app(FormTestSender::class)->built($form, ['rule' => 0]);

        Notification::assertSentOnDemand(FormSubmitted::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['owner@example.com', 'weddings@example.com']);
        $this->assertStringContainsString('"Details contains wedding"', $report->body());
    }

    public function test_the_reply_to_the_visitor_goes_to_the_chosen_address_never_a_real_visitor(): void
    {
        Notification::fake();
        $form = $this->form(['notify' => ['owner@example.com'], 'autoreply' => ['enabled' => true, 'subject' => 'Thanks {name}', 'body' => 'Hello {name}.']]);

        app(FormTestSender::class)->built($form, ['to' => 'address', 'address' => 'me@else.test', 'reply' => true]);

        Notification::assertSentOnDemand(FormAutoReply::class, function (FormAutoReply $notification, array $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === ['me@else.test']
                && $mail->subject === '[Test] Thanks Sam Sample'
                && $mail->introLines[0] === FormAutoReply::testBanner();
        });
        Notification::assertSentOnDemandTimes(FormAutoReply::class, 1);
    }

    public function test_the_text_alert_reports_each_number_and_only_texts_the_list_when_testing_the_list(): void
    {
        Notification::fake();
        $this->twilio();
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['sid' => 'SM1'], 201)
            ->push(['code' => 21610, 'message' => 'unsubscribed'], 400)]);
        $form = $this->form(['notify' => ['owner@example.com'], 'notify_sms' => ['+17325550111', '+17325550122']]);

        $report = app(FormTestSender::class)->built($form, ['text' => true]);

        $this->assertStringContainsString('Text to (732) 555-0111: sent.', $report->body());
        $this->assertStringContainsString('Text to (732) 555-0122: not sent - they replied STOP', $report->body());
        Http::assertSent(fn (Request $request): bool => str_starts_with($request['Body'], '[Test] New Enquiry enquiry from Sam Sample'));

        $report = app(FormTestSender::class)->built($form, ['to' => 'me', 'me' => 'ada@example.com', 'text' => true]);

        $this->assertStringContainsString('there is no mobile number to send it to', $report->body());
        Http::assertSentCount(2);
    }

    public function test_a_draft_form_is_tested_as_it_is_on_screen(): void
    {
        Notification::fake();
        $admin = $this->administrator();

        Livewire::actingAs($admin)
            ->test(CreateForm::class)
            ->fillForm([
                'title' => 'Unsaved',
                'slug' => 'unsaved',
                'fields' => [['type' => 'name', 'data' => ['label' => 'Your name']]],
                'settings' => ['notify' => ['typed-not-saved@example.com'], 'email_subject' => 'Draft from {name}'],
            ])
            ->callAction(TestAction::make('sendFormTest')->schemaComponent('sendTestSection', schema: 'form'), ['to' => 'all', 'address' => null, 'rule' => null, 'reply' => false, 'text' => false, 'numbers' => []])
            ->assertNotified('The test was sent');

        Notification::assertSentOnDemand(FormSubmitted::class, fn (FormSubmitted $n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['typed-not-saved@example.com']
            && $n->toMail($notifiable)->subject === '[Test] Draft from Sam Sample');
        $this->assertSame(0, Form::query()->count());
    }

    public function test_through_the_shared_sender_the_test_is_marked_and_says_who_it_came_from(): void
    {
        Http::fake(['portal.test/*' => Http::response(['id' => 1])]);
        $this->pair();
        config(['gadya-cms.seo.organization.email' => 'hello@acmedental.test']);

        $report = app(FormTestSender::class)->built($this->form(['notify' => ['owner@example.com', 'second@example.com']]), []);

        $this->assertTrue($report->succeeded());
        $this->assertStringContainsString("sent to owner@example.com, second@example.com from 'Acme Dental <acme-dental@on.gadya.media>'. It can take a few minutes; check junk too.", $report->body());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['purpose'] === 'test'
            && str_starts_with($request['subject'], '[Test] ')
            && $request['reply_to'][0]['email'] === 'sam.sample@example.com'
            && str_contains($request['html'], FormSubmitted::testBanner()));
    }

    public function test_a_refusal_from_the_portal_is_reported_with_its_reason_and_a_fix(): void
    {
        Http::fake(['portal.test/*' => Http::response(['message' => 'This site has sent its 100 messages for the hour. The rest will go out once the hour is up.'], 429)]);
        $this->pair();

        $report = app(FormTestSender::class)->built($this->form(['notify' => ['owner@example.com']]), []);

        $this->assertFalse($report->succeeded());
        $this->assertSame('The test could not be sent', $report->title());
        $this->assertStringContainsString('This site has sent its 100 messages for the hour', $report->body());
        $this->assertStringContainsString('Wait an hour and try again', $report->body());

    }

    public function test_a_site_switched_off_at_the_portal_is_told_how_to_get_it_back(): void
    {
        Http::fake(['portal.test/*' => Http::response(['message' => 'Email sending for this site is switched off. Please get in touch with Gadya Media.'], 403)]);
        $this->pair();

        $this->assertStringContainsString('switched off at Gadya Media', app(FormTestSender::class)->built($this->form(['notify' => ['owner@example.com']]), [])->body());
    }

    public function test_the_panel_says_who_sends_through_the_shared_sender(): void
    {
        Http::fake(['portal.test/*' => Http::response([
            'address' => 'acme-dental-2@on.gadya.media',
            'name' => 'Acme Dental',
            'enabled' => true,
            'sent_this_hour' => 12,
            'per_hour' => 100,
            'recent' => [
                ['sent_at' => now()->toIso8601String(), 'to' => 'owner@acmedental.test', 'recipients' => 1, 'subject' => '[Test] New', 'status' => 'sent', 'failed' => false, 'purpose' => 'test'],
                ['sent_at' => now()->toIso8601String(), 'to' => 'x@y.test', 'recipients' => 1, 'subject' => 'Lost', 'status' => 'failed', 'failed' => true, 'reason' => 'The mail provider did not accept it.'],
            ],
        ])]);
        $this->pair();
        config(['gadya-cms.seo.organization.email' => 'hello@acmedental.test']);

        $panel = app(SenderPanel::class)->describe();

        $this->assertSame('shared', $panel['via']);
        $this->assertSame('Sent by Gadya Media (on.gadya.media)', $panel['transport']);
        $this->assertSame('Acme Dental <acme-dental-2@on.gadya.media>', $panel['from']);
        $this->assertSame('hello@acmedental.test', $panel['reply_to']);
        $this->assertSame('12 of 100 emails this hour', $panel['allowance']);
        $this->assertSame([], $panel['warnings']);
        $this->assertTrue($panel['recent'][1]['failed']);

        $this->actingAs($this->administrator())
            ->get('/admin/emails')
            ->assertOk()
            ->assertSee('Who is this sent by?')
            ->assertSee('12 of 100 emails this hour')
            ->assertSee('The mail provider did not accept it.')
            ->assertSee('(a test)');
    }

    public function test_the_panel_says_who_sends_through_the_sites_own_smtp(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.from' => ['address' => 'hello@acmedental.test', 'name' => 'Acme Dental'],
            'app.url' => 'https://www.acmedental.test',
        ]);

        $panel = app(SenderPanel::class)->describe();

        $this->assertSame('own', $panel['via']);
        $this->assertSame("Sent by this site's own mail service (smtp)", $panel['transport']);
        $this->assertSame('Acme Dental <hello@acmedental.test>', $panel['from']);
        $this->assertNull($panel['allowance']);
        $this->assertSame([], $panel['recent']);
        $this->assertSame([], $panel['warnings']);
    }

    public function test_each_thing_that_looks_wrong_is_warned_about_with_a_fix(): void
    {
        $panel = app(SenderPanel::class);
        $problems = fn (): string => collect($panel->describe()['warnings'])->map(fn (array $w): string => $w['problem'].' '.$w['fix'])->implode("\n");

        /* Not paired, and nothing of its own. */
        config(['mail.default' => 'log', 'mail.from' => ['address' => null, 'name' => null]]);
        $this->assertStringContainsString('not paired with Gadya Media', $problems());
        $this->assertStringContainsString('No From address is set', $problems());
        $this->assertStringContainsString('Pair it under Gadya Support', $problems());

        /* A mailer that goes nowhere, on the live site. */
        config(['mail.from' => ['address' => 'hello@acmedental.test', 'name' => 'Acme'], 'app.url' => 'https://acmedental.test']);
        $this->assertStringNotContainsString('writes email to a file', $problems());
        $this->app['env'] = 'production';
        $this->assertStringContainsString('writes email to a file', $problems());
        $this->app['env'] = 'testing';

        /* A different domain from the site's own. */
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.from.address' => 'sender@mailhost.example']);
        $this->assertStringContainsString('which is not this site\'s domain (acmedental.test)', $problems());
        $this->assertStringContainsString('often marked as spam', $problems());

        config(['mail.from.address' => 'sender@mail.acmedental.test']);
        $this->assertSame('', $problems());
    }

    public function test_a_switched_off_shared_sender_and_an_unreachable_portal_are_warned_about(): void
    {
        Http::fake(['portal.test/*' => Http::response(['address' => 'a@on.gadya.media', 'enabled' => false, 'sent_this_hour' => 0, 'per_hour' => 100, 'recent' => []])]);
        $this->pair();

        $warnings = app(SenderPanel::class)->describe(fresh: true)['warnings'];

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('switched off for this site', $warnings[0]['problem']);
        $this->assertStringContainsString('help@support.gadya.media', $warnings[0]['fix']);
    }

    public function test_a_portal_that_does_not_answer_is_warned_about(): void
    {
        Http::fake(['portal.test/*' => Http::response('nope', 500)]);
        $this->pair();

        $this->assertStringContainsString('could not be asked', app(SenderPanel::class)->describe(fresh: true)['warnings'][0]['problem']);
    }

    public function test_a_test_on_a_site_that_only_logs_says_it_was_not_really_sent(): void
    {
        config(['mail.default' => 'log', 'mail.from' => ['address' => 'hello@acmedental.test', 'name' => 'Acme']]);

        $report = app(FormTestSender::class)->built($this->form(['notify' => ['owner@example.com']]), []);

        $this->assertStringContainsString('written to the site\'s log, not sent', $report->body());
    }

    public function test_a_configured_form_is_tested_from_the_enquiry_emails_screen_as_it_is_on_screen(): void
    {
        Notification::fake();
        config(['gadya-cms.forms.forms' => ['contact' => [
            'label' => 'Contact',
            'fields' => ['name' => 'required', 'email' => 'required|email', 'message' => 'required'],
            'notify' => ['owner@example.com'],
        ]]]);

        Livewire::actingAs($this->administrator())
            ->test(Emails::class)
            ->set('data.notify.contact', ['front-desk@example.com'])
            ->set('data.replies.contact', ['enabled' => true, 'subject' => 'Thanks {{ name }}', 'body' => 'Hello {{ name }}.'])
            ->callAction(TestAction::make('sendFormTest_contact')->schemaComponent('form_contact', schema: 'form'), ['to' => 'all', 'address' => null, 'rule' => null, 'reply' => true])
            ->assertNotified('The test was sent');

        Notification::assertSentOnDemand(FormSubmitted::class, fn (FormSubmitted $n, $c, $notifiable): bool => $notifiable->routes['mail'] === ['owner@example.com', 'front-desk@example.com']
            && $n->toMail($notifiable)->subject === '[Test] New Contact enquiry from Sam Sample');
        Notification::assertSentOnDemand(FormAutoReply::class, fn (FormAutoReply $n): bool => $n->reply['subject'] === 'Thanks {{ name }}');
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_the_generic_test_email_says_who_it_was_sent_from(): void
    {
        Http::fake(['portal.test/*' => Http::response(['id' => 1])]);
        $this->pair();

        $report = app(FormTestSender::class)->generic('ada@example.com', 'Ada');

        $this->assertStringContainsString("sent to ada@example.com from 'Acme Dental <acme-dental@on.gadya.media>'", $report->body());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['purpose'] === 'test'
            && str_contains($request['text'], 'It was sent from Acme Dental <acme-dental@on.gadya.media>.'));
    }
}
