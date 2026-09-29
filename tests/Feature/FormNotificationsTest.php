<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Pages\TextMessages;
use Gadya\Cms\Forms\Builder\FormExport;
use Gadya\Cms\Forms\Builder\FormNotifier;
use Gadya\Cms\Forms\Builder\FormWebhooks;
use Gadya\Cms\Jobs\DeliverFormWebhook;
use Gadya\Cms\Jobs\SendTextAlert;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Models\FormWebhookDelivery;
use Gadya\Cms\Models\Option;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Portal\SubmissionPush;
use Gadya\Cms\Sms\PhoneNumbers;
use Gadya\Cms\Sms\TextAlerts;
use Gadya\Cms\Sms\TwilioSettings;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;

/**
 * Who hears about an enquiry sent through a built form - by email, by
 * text through the client's own Twilio, by webhook, in the portal - and
 * the spreadsheet of them all.
 */
class FormNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    private function form(array $settings = []): Form
    {
        return Form::factory()->published()->withSettings($settings)->withFields([
            ['type' => 'name', 'key' => 'your_name', 'label' => 'Your name', 'required' => true],
            ['type' => 'email', 'key' => 'your_email', 'label' => 'Email', 'required' => true],
            ['type' => 'phone', 'key' => 'mobile', 'label' => 'Phone'],
            ['type' => 'select', 'key' => 'service', 'label' => 'Service', 'options' => [['key' => 'party', 'label' => 'Party'], ['key' => 'catering', 'label' => 'Catering']]],
            ['type' => 'long_text', 'key' => 'details', 'label' => 'Details'],
        ])->create(['slug' => 'enquiry', 'title' => 'Enquiry']);
    }

    private function send(string $service = 'catering'): FormSubmission
    {
        $this->postJson('/cms/forms/enquiry', [
            'your_name' => ['first' => 'Pat', 'last' => 'Jones'],
            'your_email' => 'pat@example.com',
            'mobile' => '732-555-0199',
            'service' => $service,
            'details' => 'We need food for forty people on Saturday afternoon, with vegetarian options please.',
        ])->assertOk();

        return FormSubmission::query()->latest('id')->firstOrFail();
    }

    private function twilio(): void
    {
        app(TwilioSettings::class)->save(['sid' => 'AC'.str_repeat('a', 32), 'token' => 'secret-token', 'from' => '(732) 555-0100']);
    }

    public function test_staff_are_emailed_with_their_own_subject_and_replies_go_to_the_sender(): void
    {
        $this->form(['notify' => ['owner@example.com', 'second@example.com'], 'email_subject' => '{service} request from {name}', 'email_body' => 'A new one for {business}.']);

        $submission = $this->send();

        Notification::assertSentOnDemand(FormSubmitted::class, function (FormSubmitted $notification, array $channels, $notifiable) use ($submission): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === ['owner@example.com', 'second@example.com']
                && $mail->subject === 'Catering request from Pat Jones'
                && $mail->replyTo === [['pat@example.com', 'Pat Jones']]
                && str_contains(implode("\n", $mail->introLines), '**Service:** Catering')
                && str_contains(implode("\n", $mail->introLines), 'A new one for '.config('gadya-cms.brand.name'))
                && $notification->submission->is($submission);
        });
    }

    public function test_a_rule_sends_some_enquiries_elsewhere_as_well_or_instead(): void
    {
        $form = $this->form([
            'notify' => ['owner@example.com'],
            'routes' => [
                ['field' => 'service', 'operator' => 'equals', 'value' => 'Catering', 'emails' => ['catering@example.com']],
                ['field' => 'details', 'operator' => 'contains', 'value' => 'wedding', 'emails' => ['weddings@example.com'], 'instead' => true],
            ],
        ]);
        $notifier = app(FormNotifier::class);

        $this->assertSame(['owner@example.com', 'catering@example.com'], $notifier->recipients($form, $this->send('catering'))['emails']);
        $this->assertSame(['owner@example.com'], $notifier->recipients($form, $this->send('party'))['emails']);

        $wedding = FormSubmission::query()->create(['site_id' => $form->site_id, 'form' => 'enquiry', 'data' => ['service' => 'Catering', 'details' => 'For our Wedding'], 'created_at' => now()]);
        $this->assertSame(['weddings@example.com'], $notifier->recipients($form, $wedding)['emails']);
    }

    public function test_the_sender_gets_the_reply_the_client_wrote(): void
    {
        $this->form(['autoreply' => ['enabled' => true, 'subject' => 'Thanks {name}', 'body' => "Hello {name},\n\nWe have your {service} request.\n\n{{ business }}"]]);

        $this->send();

        Notification::assertSentOnDemand(FormAutoReply::class, function (FormAutoReply $notification, array $channels, $notifiable): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'pat@example.com'
                && $mail->subject === 'Thanks Pat Jones'
                && $mail->introLines === ['Hello Pat Jones,', 'We have your Catering request.', config('gadya-cms.brand.name')];
        });
    }

    public function test_numbers_are_written_the_way_twilio_takes_them(): void
    {
        $this->assertSame('+17325550100', PhoneNumbers::normalise('(732) 555-0100'));
        $this->assertSame('+17325550100', PhoneNumbers::normalise('1 732 555 0100'));
        $this->assertSame('+447700900123', PhoneNumbers::normalise('+44 7700 900123'));
        $this->assertNull(PhoneNumbers::normalise('555-0100'));
        $this->assertNull(PhoneNumbers::normalise('0123456789'));
        $this->assertSame('(732) 555-0100', PhoneNumbers::display('+17325550100'));
    }

    public function test_staff_are_texted_through_the_clients_own_twilio_and_the_first_text_says_how_to_stop(): void
    {
        $this->twilio();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);
        $this->form(['notify_sms' => ['+17325550111'], 'routes' => [['field' => 'service', 'operator' => 'equals', 'value' => 'catering', 'sms' => ['732 555 0122']]]]);

        $this->send();
        $this->send('party');

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);

        $this->assertCount(3, $sent);
        $first = $sent->first();
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC'.str_repeat('a', 32).'/Messages.json', $first->url());
        $this->assertSame('+17325550111', $first['To']);
        $this->assertSame('+17325550100', $first['From']);
        $this->assertStringStartsWith('New Enquiry enquiry from Pat Jones: We need food for forty people', $first['Body']);
        $this->assertStringContainsString('/admin/', $first['Body']);
        $this->assertStringEndsWith(TextAlerts::STOP_LINE, $first['Body']);
        $this->assertTrue($first->hasHeader('Authorization', 'Basic '.base64_encode('AC'.str_repeat('a', 32).':secret-token')));

        $this->assertSame('+17325550122', $sent[1]['To']);
        $this->assertStringNotContainsString(TextAlerts::STOP_LINE, $sent[2]['Body'], 'Only the first text to a number says how to stop.');
    }

    public function test_a_number_that_replied_stop_is_marked_and_never_texted_again(): void
    {
        $this->twilio();
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21610, 'message' => 'Attempt to send to unsubscribed recipient', 'status' => 400], 400)]);
        $this->form(['notify_sms' => ['+17325550111']]);

        $this->send();

        $alerts = app(TextAlerts::class);
        $this->assertTrue($alerts->isOptedOut('+17325550111'));

        $this->send();
        Http::assertSentCount(1);
        $this->assertSame(0, $alerts->queue(['+17325550111'], 'Hello'));

        $alerts->optBackIn('+17325550111');
        $this->assertFalse($alerts->isOptedOut('+17325550111'));
    }

    public function test_twilio_being_down_is_retried_and_a_refusal_is_not(): void
    {
        $this->twilio();
        $alerts = app(TextAlerts::class);

        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['message' => 'Service unavailable'], 503)
            ->push(['code' => 21211, 'message' => 'Invalid To number'], 400)]);

        try {
            (new SendTextAlert('+17325550111', 'Hello'))->handle($alerts);
            $this->fail('A 5xx should be thrown so the queue tries again.');
        } catch (RuntimeException) {
            $this->assertSame(4, (new SendTextAlert('+17325550111', 'Hello'))->tries);
        }

        (new SendTextAlert('+17325550111', 'Hello'))->handle($alerts);
        $this->assertSame('Invalid To number', $alerts->state('+17325550111')['last_error']);
    }

    public function test_nothing_is_texted_and_the_visitor_is_unaffected_when_texting_is_not_set_up(): void
    {
        Http::fake();
        Queue::fake();
        $this->form(['notify_sms' => ['+17325550111']]);

        $this->send();

        Queue::assertNotPushed(SendTextAlert::class);
        Http::assertNothingSent();
        $this->assertFalse(app(TextAlerts::class)->enabled());
    }

    public function test_every_enquiry_goes_to_each_webhook_signed_and_logged(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);
        $this->form(['webhooks' => [
            ['url' => 'https://hooks.example.com/catch', 'secret' => 'shh', 'active' => true],
            ['url' => 'https://hooks.example.com/off', 'active' => false],
            ['url' => 'http://127.0.0.1/admin', 'active' => true],
        ]]);

        $submission = $this->send();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($submission): bool {
            return $request->url() === 'https://hooks.example.com/catch'
                && $request->header(FormWebhooks::SIGNATURE_HEADER)[0] === FormWebhooks::sign($request->body(), 'shh')
                && $request['data']['service'] === 'Catering'
                && $request['labels']['service'] === 'Service'
                && $request['submission']['id'] === $submission->getKey();
        });

        $delivery = FormWebhookDelivery::query()->sole();
        $this->assertSame(FormWebhookDelivery::SUCCEEDED, $delivery->status);
        $this->assertSame(200, $delivery->response_status);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_a_webhook_that_fails_is_retried_then_marked_failed_and_a_refusal_is_not_retried(): void
    {
        $form = $this->form();
        $submission = $this->send();
        $delivery = FormWebhookDelivery::query()->create(['form_id' => $form->getKey(), 'submission_id' => $submission->getKey(), 'url' => 'https://hooks.example.com/catch']);
        $job = new DeliverFormWebhook($delivery);

        Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 502)->push('gone', 410)]);

        try {
            $job->handle(app(FormWebhooks::class));
            $this->fail('A 5xx is tried again.');
        } catch (RuntimeException) {
            $this->assertSame(FormWebhookDelivery::PENDING, $delivery->fresh()->status);
        }

        $job->handle(app(FormWebhooks::class));
        $this->assertSame(FormWebhookDelivery::FAILED, $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(5, $job->tries);

        $job->failed(new RuntimeException('gave up'));
        $this->assertSame(FormWebhookDelivery::FAILED, $delivery->fresh()->status);
    }

    public function test_the_portal_gets_the_name_email_phone_and_message_from_the_kinds_of_question(): void
    {
        $this->form();
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
        Http::fake(['portal.test/*' => Http::response(['data' => ['id' => 1]], 201)]);

        $submission = $this->send();

        $payload = app(SubmissionPush::class)->payload($submission);

        $this->assertSame('Pat Jones', $payload['name']);
        $this->assertSame('pat@example.com', $payload['email']);
        $this->assertSame('732-555-0199', $payload['phone']);
        $this->assertStringStartsWith('We need food', $payload['message']);
        $this->assertFalse($payload['callback_requested']);
    }

    public function test_a_call_back_forms_consent_reaches_the_portal_word_for_word(): void
    {
        Form::factory()->published()->withSettings(['callback' => true])->withFields([
            ['type' => 'short_text', 'key' => 'name', 'label' => 'Name', 'required' => true],
            ['type' => 'phone', 'key' => 'phone', 'label' => 'Phone', 'required' => true],
            ['type' => 'consent', 'key' => 'ok', 'label' => 'You may ring me back, perhaps with an AI assistant.', 'required' => true, 'rules' => ['callback' => true]],
        ])->create(['slug' => 'ring-me']);

        $this->postJson('/cms/forms/ring-me', ['name' => 'Sam', 'phone' => '7325550100'])->assertStatus(422)->assertJsonValidationErrors(['ok']);
        $this->postJson('/cms/forms/ring-me', ['name' => 'Sam', 'phone' => '7325550100', 'ok' => '1'])->assertOk();

        $payload = app(SubmissionPush::class)->payload(FormSubmission::query()->sole());

        $this->assertTrue($payload['callback_requested']);
        $this->assertSame('You may ring me back, perhaps with an AI assistant.', $payload['callback_consent_text']);
        $this->assertSame('Yes', FormSubmission::query()->sole()->data['ok']);
    }

    public function test_a_forms_enquiries_download_with_a_column_per_question(): void
    {
        $this->form();
        $this->send();
        FormSubmission::query()->create(['site_id' => 1, 'form' => 'enquiry', 'data' => ['details' => '=HYPERLINK("x")'], 'created_at' => now()]);

        $rows = app(FormExport::class)->rows(FormSubmission::query()->where('form', 'enquiry')->orderBy('id')->get());

        $this->assertSame(['Received', 'Status', 'Answered', 'Follow up', 'Notes', 'Page', 'Country', 'Your name', 'Email', 'Phone', 'Service', 'Details'], $rows[0]);
        $this->assertSame('Catering', $rows[1][10]);
        $this->assertSame("'=HYPERLINK(\"x\")", $rows[2][11], 'A formula typed into a form is never run by a spreadsheet.');
        $this->assertTrue(FormExport::canWriteExcel());

        $this->actingAs($this->editor());
        $response = app(FormExport::class)->download(FormSubmission::query()->get(), 'Enquiry enquiries', 'xlsx');
        $this->assertStringEndsWith('.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_client_enters_her_twilio_details_and_sends_herself_a_test(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        Livewire::actingAs($this->administrator())
            ->test(TextMessages::class)
            ->fillForm(['sid' => 'AC'.str_repeat('b', 32), 'token' => 'tok-123', 'from' => '732 555 0100'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('test', ['number' => '(732) 555-0177'])
            ->assertNotified('Sent');

        $twilio = app(TwilioSettings::class);
        $this->assertSame('tok-123', $twilio->authToken());
        $this->assertSame('+17325550100', $twilio->from());
        $this->assertNotSame('tok-123', Option::query()->where('key', 'sms.twilio.token')->value('value'), 'The token is stored encrypted.');

        $twilio->save(['sid' => 'AC'.str_repeat('b', 32), 'token' => '', 'from' => '732 555 0100']);
        $this->assertSame('tok-123', $twilio->authToken(), 'A blank token keeps the one saved.');
        Http::assertSent(fn (Request $request): bool => $request['To'] === '+17325550177');
    }
}
