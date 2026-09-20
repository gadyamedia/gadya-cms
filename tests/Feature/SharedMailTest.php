<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Pages\Emails;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Notifications\TestEmail;
use Gadya\Cms\Support\InstallAudit;
use Gadya\Cms\Tests\TestCase;
use Gadya\Connect\Models\Connection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * A client who never set up a mail service still sends email: the message
 * goes to the Gadya Media portal over the link pairing made, and the
 * portal sends it. Nothing about Cloudflare lives on this site.
 */
class SharedMailTest extends TestCase
{
    private function pair(string $name = 'Acme Dental'): Connection
    {
        return Connection::query()->create([
            'site_id' => 7,
            'portal_url' => 'https://portal.test',
            'secret' => 'shhh',
            'site_name' => $name,
        ]);
    }

    public function test_a_paired_site_with_no_mailer_of_its_own_sends_through_the_portal(): void
    {
        Http::fake(['portal.test/*' => Http::response(['sent' => true])]);
        config([
            'gadya-cms.mail.shared' => true,
            'gadya-cms.seo.organization.email' => 'hello@acmedental.test',
            'gadya-cms.seo.site_name' => 'Acme Dental',
        ]);
        $this->pair();

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));

        $this->assertSame('gadya', config('mail.default'));
        $this->assertSame('acme-dental@on.gadya.media', config('mail.from.address'));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://portal.test/api/connect/v1/mail', $request->url());
            $this->assertSame('7', $request->header('X-Gadya-Site')[0] ?? $request->header('x-gadya-site')[0] ?? '');

            $this->assertSame('acme-dental@on.gadya.media', $request['from']['email']);
            $this->assertSame('Acme Dental', $request['from']['name']);
            $this->assertSame('someone@example.test', $request['to'][0]['email']);

            /* Nobody reads the shared address, so a reply goes to the business. */
            $this->assertSame('hello@acmedental.test', $request['reply_to'][0]['email']);

            $this->assertStringContainsString('gadya.media', $request['html']);
            $this->assertStringContainsString('It works.', $request['html']);
            $this->assertStringContainsString('gadya.media', $request['text']);

            return true;
        });
    }

    public function test_the_mailbox_can_be_named_and_the_footer_switched_off(): void
    {
        Http::fake(['portal.test/*' => Http::response(['sent' => true])]);
        config([
            'gadya-cms.mail.shared' => true,
            'gadya-cms.mail.mailbox' => 'enquiries',
            'gadya-cms.mail.domain' => 'on.example.test',
            'gadya-cms.mail.footer' => false,
        ]);
        $this->pair();

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('enquiries@on.example.test', $request['from']['email']);
            $this->assertStringNotContainsString('gadya.media', (string) $request['html']);

            return true;
        });
    }

    public function test_a_site_that_is_not_paired_is_left_to_its_own_mailer(): void
    {
        Http::fake();
        config(['gadya-cms.mail.shared' => true]);

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));

        $this->assertNotSame('gadya', config('mail.default'));
        $this->assertFalse(app(SharedSender::class)->enabled());
        Http::assertNothingSent();
    }

    public function test_a_site_with_its_own_mail_service_is_never_taken_over(): void
    {
        config(['gadya-cms.mail.shared' => 'auto', 'mail.default' => 'smtp']);
        $this->pair();

        $this->assertFalse(app(SharedSender::class)->enabled());
    }

    public function test_the_portal_refusing_the_message_is_reported_not_swallowed(): void
    {
        Http::fake(['portal.test/*' => Http::response(['message' => 'This site is suspended.'], 422)]);
        config(['gadya-cms.mail.shared' => true]);
        $this->pair();

        $this->expectExceptionMessage('This site is suspended.');

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));
    }

    public function test_the_panel_says_who_sends_the_email_and_can_prove_it(): void
    {
        Http::fake(['portal.test/*' => Http::response(['sent' => true])]);
        config(['gadya-cms.mail.shared' => true]);
        $this->pair();

        $admin = $this->administrator();

        $this->actingAs($admin)
            ->get('/admin/emails')
            ->assertOk()
            ->assertSee('acme-dental@on.gadya.media')
            ->assertSee('Send me a test email');

        Livewire::actingAs($admin)
            ->test(Emails::class)
            ->callAction('sendTest')
            ->assertNotified();

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request['subject'], 'Test email'));
    }

    public function test_the_audit_says_whether_the_site_can_send_email(): void
    {
        $check = fn (): array => collect(app(InstallAudit::class)->checks())->firstWhere('label', 'The site can send email');

        config(['gadya-cms.mail.shared' => true, 'mail.default' => 'log']);
        $this->assertSame(InstallAudit::TODO, $check()['status']);

        $this->pair();
        app()->forgetInstance(SharedSender::class);

        $this->assertSame(InstallAudit::OK, $check()['status']);
    }
}
