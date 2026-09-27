<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\CallbackForm;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Portal\SubmissionPush;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * "Speak with our team": a visitor leaves a number, agrees in so many
 * words to be rung back, and the portal is told - consent and all.
 */
class CallBackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
        config(['gadya-cms.brand.name' => 'Acme Dental']);
    }

    public function test_the_button_opens_a_labelled_dialog_with_an_unticked_consent_box(): void
    {
        $html = Blade::render('<x-gadya-cms::call-back />');

        $this->assertStringContainsString('Speak with our team', $html);
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        $this->assertMatchesRegularExpression('/<dialog id="(cms-callback-[a-z0-9]+)"[^>]*aria-labelledby="\1-heading"[^>]*aria-describedby="\1-intro"/', $html);
        $this->assertStringContainsString('action="http://localhost/cms/forms/callback"', str_replace('cms.test', 'localhost', $html));

        foreach (['name' => 'Your name', 'phone' => 'Phone number', 'message' => 'What is it about?'] as $name => $label) {
            $this->assertMatchesRegularExpression('/<label for="(cms-callback-[a-z0-9]+-'.$name.')">'.preg_quote($label, '/').'.*?<(input|textarea) id="\1" name="'.$name.'"/s', $html);
        }

        $this->assertMatchesRegularExpression('/<input id="[^"]+-consent" name="consent" type="checkbox" value="1" required\s*>/', $html, 'The box starts unticked.');
        $this->assertStringContainsString('I agree to Acme Dental calling me back', $html);
        $this->assertStringContainsString("event.key !== 'Tab'", $html, 'Focus stays inside the dialog.');
        $this->assertStringContainsString('dialog.showModal()', $html);
    }

    public function test_the_wording_is_the_sites_own_and_the_script_comes_once(): void
    {
        config(['gadya-cms.portal.callback.consent_text' => 'Yes, {{ business }} may phone me.']);

        $html = Blade::render('<x-gadya-cms::call-back button="Ring me" /><x-gadya-cms::call-back />');

        $this->assertStringContainsString('Ring me', $html);
        $this->assertStringContainsString('Yes, Acme Dental may phone me.', $html);
        $this->assertSame(1, substr_count($html, '<script'), 'Two buttons on a page share one script.');
        $this->assertSame(2, substr_count($html, '<dialog'));
    }

    public function test_a_request_is_kept_with_the_exact_consent_and_the_team_is_told(): void
    {
        config(['gadya-cms.forms.forms.contact.notify' => ['owner@example.com']]);

        $this->from('/')
            ->post('/cms/forms/callback', ['name' => 'Pat', 'phone' => '+44 1273 000000', 'message' => 'About a filling', 'consent' => '1'], ['REMOTE_ADDR' => '203.0.113.9'])
            ->assertRedirect('/')
            ->assertSessionHas('gadya-cms.form.callback', 'Thank you. We will call you shortly.');

        $submission = FormSubmission::query()->sole();

        $this->assertSame('callback', $submission->form);
        $this->assertSame(CallbackForm::consentText(), $submission->consent['text']);
        $this->assertStringContainsString('Acme Dental', $submission->consent['text']);
        $this->assertSame('203.0.113.9', $submission->consent['ip']);
        $this->assertNotNull($submission->consent['at']);

        Notification::assertSentOnDemand(FormSubmitted::class, fn ($notification, array $channels, $notifiable): bool => in_array('owner@example.com', $notifiable->routes['mail'] ?? [], true));
    }

    public function test_nobody_is_rung_without_ticking_the_box(): void
    {
        $this->from('/')
            ->post('/cms/forms/callback', ['name' => 'Pat', 'phone' => '01273 000000'])
            ->assertRedirect('/')
            ->assertSessionHasErrorsIn('gadya-cms.callback', ['consent']);

        $this->from('/')
            ->post('/cms/forms/callback', ['name' => 'Pat', 'phone' => 'call me maybe', 'consent' => '1'])
            ->assertSessionHasErrorsIn('gadya-cms.callback', ['phone']);

        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_a_bot_is_answered_as_though_it_worked_and_nothing_is_kept(): void
    {
        $this->from('/')
            ->post('/cms/forms/callback', ['name' => 'Bot', 'phone' => '0100000000', 'consent' => '1', 'website' => 'http://spam.test'])
            ->assertSessionHas('gadya-cms.form.callback');

        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_the_portal_is_told_a_call_back_was_asked_for_with_the_consent_given(): void
    {
        Connection::query()->create(['site_id' => 7, 'portal_url' => 'https://portal.test', 'secret' => 'shhh']);
        Http::fake(['portal.test/*' => Http::response(['data' => ['id' => 9, 'status' => 'received']], 201)]);

        $this->from('/')->post('/cms/forms/callback', ['name' => 'Pat', 'phone' => '01273 000000', 'consent' => '1'], ['REMOTE_ADDR' => '203.0.113.9']);

        $submission = FormSubmission::query()->sole();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://portal.test'.SubmissionPush::PATH
            && $request['form'] === 'callback'
            && $request['phone'] === '01273 000000'
            && $request['callback_requested'] === true
            && $request['callback_consent_text'] === CallbackForm::consentText()
            && $request['callback_consented_at'] === $submission->consent['at']
            && $request['callback_consent_ip'] === '203.0.113.9');
    }

    public function test_an_ordinary_enquiry_is_not_a_call_back(): void
    {
        $submission = FormSubmission::query()->create([
            'site_id' => 1,
            'form' => 'contact',
            'data' => ['name' => 'Ada', 'phone' => '0100'],
            'created_at' => now(),
        ]);

        $payload = app(SubmissionPush::class)->payload($submission);

        $this->assertFalse($payload['callback_requested']);
        $this->assertNull($payload['callback_consented_at']);
    }
}
