<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email that tells staff about an enquiry is Gadya's, not Laravel's:
 * the logo on top, "Emails powered by Gadya" at the foot.
 */
class FormEmailBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
    }

    private function mail(array $data = [], bool $test = false): MailMessage
    {
        $submission = FormSubmission::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'form' => 'contact',
            'data' => $data ?: ['name' => 'Pat', 'email' => 'pat@example.com', 'message' => 'Can you do Saturday?'],
            'path' => '/contact',
            'created_at' => now(),
        ]);

        return (new FormSubmitted($submission, FormDefinition::find('contact'), test: $test))->toMail(new \stdClass);
    }

    public function test_the_email_carries_gadya_s_logo_and_says_who_powers_it(): void
    {
        $html = (string) $this->mail()->render();

        $this->assertStringContainsString('https://gadya.media/brand/gadya-logo.png', $html);
        $this->assertStringContainsString('Emails powered by', $html);
        $this->assertStringContainsString('Someone filled in the', $html);
        $this->assertStringContainsString('Can you do Saturday?', $html);
        $this->assertStringContainsString('Open in the admin', $html);
        $this->assertStringContainsString('data-gadya-mail', $html, 'The marker that stops the sent-by line being added a second time.');
        $this->assertStringNotContainsString('laravel.com', $html);
    }

    public function test_what_a_visitor_typed_is_shown_and_never_run(): void
    {
        $html = (string) $this->mail(['name' => 'Pat', 'message' => '<script>alert(1)</script> <b>hi</b>'])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>hi</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_link_or_picture_a_visitor_types_is_only_words(): void
    {
        $html = (string) $this->mail(['name' => 'Pat', 'message' => "[click](https://evil.test) ![](https://tracker.test/p.gif)\nsecond line"])->render();

        $this->assertStringNotContainsString('href="https://evil.test"', $html);
        $this->assertStringNotContainsString('<img src="https://tracker.test', $html);
        $this->assertStringContainsString('second line', $html);
        $this->assertStringContainsString('<strong>Message:</strong>', $html);
    }

    public function test_the_plain_text_version_says_it_too_without_the_markdown(): void
    {
        $mail = $this->mail();
        $text = view('gadya-cms::mail.enquiry-text', $mail->data())->render();

        $this->assertStringContainsString('Emails powered by Gadya - https://gadya.media', $text);
        $this->assertStringContainsString('Message: Can you do Saturday?', $text);
        $this->assertStringNotContainsString('**', $text);
    }

    public function test_a_test_says_so_in_the_branded_email_too(): void
    {
        $html = (string) $this->mail(test: true)->render();

        $this->assertStringContainsString(FormSubmitted::testBanner(), $html);
        $this->assertStringContainsString('Emails powered by', $html);
    }

    public function test_the_plain_notification_comes_back_when_branding_is_switched_off(): void
    {
        config(['gadya-cms.mail.branded' => false]);

        $mail = $this->mail();

        $this->assertNull($mail->view);
        $this->assertStringNotContainsString('Emails powered by', (string) $mail->render());
    }

    public function test_the_reply_to_the_visitor_is_never_branded(): void
    {
        $submission = FormSubmission::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'form' => 'contact',
            'data' => ['name' => 'Pat', 'email' => 'pat@example.com'],
            'created_at' => now(),
        ]);

        $mail = (new FormAutoReply($submission, ['subject' => 'Thanks', 'body' => 'We got it.']))->toMail(new \stdClass);

        $this->assertNull($mail->view);
        $this->assertStringNotContainsString('gadya-logo.png', (string) $mail->render());
    }
}
