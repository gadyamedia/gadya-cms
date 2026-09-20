<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Filament\Pages\Emails;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Notifications\TestEmail;
use Gadya\Cms\Seo\SiteSetup;
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
            if ($request->method() !== 'POST') {
                return false;
            }

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
            if ($request->method() !== 'POST') {
                return false;
            }

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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains((string) $request['subject'], 'Test email'));
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

    public function test_the_panel_shows_the_address_the_portal_holds_and_what_has_gone_out(): void
    {
        Http::fake(['portal.test/*' => Http::response([
            'address' => 'acme-dental-2@on.gadya.media',
            'enabled' => true,
            'sent_this_hour' => 3,
            'per_hour' => 100,
            'recent' => [
                ['sent_at' => now()->toIso8601String(), 'to' => 'owner@acmedental.test', 'recipients' => 1, 'subject' => 'New Contact enquiry', 'status' => 'sent', 'failed' => false],
                ['sent_at' => now()->subHour()->toIso8601String(), 'to' => 'someone@example.test', 'recipients' => 1, 'subject' => 'One that did not go', 'status' => 'failed', 'failed' => true],
            ],
        ])]);
        config(['gadya-cms.mail.shared' => true]);
        $this->pair();

        $this->actingAs($this->administrator())
            ->get('/admin/emails')
            ->assertOk()
            /* The portal's answer, not this site's guess from its own name. */
            ->assertSee('acme-dental-2@on.gadya.media')
            ->assertDontSee('acme-dental@on.gadya.media')
            ->assertSee('New Contact enquiry')
            ->assertSee('Did not send');
    }

    public function test_the_client_chooses_where_replies_go(): void
    {
        Http::fake(['portal.test/*' => Http::response(['sent' => true])]);
        config([
            'gadya-cms.mail.shared' => true,
            'gadya-cms.seo.organization.email' => 'from-the-config@acmedental.test',
        ]);
        $this->pair();

        Livewire::actingAs($this->administrator())
            ->test(Emails::class)
            ->set('data.reply_to', 'reception@acmedental.test')
            ->call('save')
            ->assertHasNoErrors();

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $this->assertSame('reception@acmedental.test', $request['reply_to'][0]['email']);

            return true;
        });
    }

    public function test_a_slow_portal_cannot_hold_a_form_submission_open_for_long(): void
    {
        config(['gadya-cms.mail.shared' => true, 'gadya-connect.timeout' => 15]);
        $this->pair();

        $seen = null;

        Http::fake(function () use (&$seen) {
            $seen = config('gadya-connect.timeout');

            return Http::response(['sent' => true]);
        });

        Notification::route('mail', 'someone@example.test')->notify(new TestEmail('Ada'));

        $this->assertSame(8, $seen);
        /* And the check-in's own timeout is put back. */
        $this->assertSame(15, config('gadya-connect.timeout'));
    }

    public function test_the_audit_asks_for_a_queue_when_gadya_sends_the_email(): void
    {
        $check = fn (): array => collect(app(InstallAudit::class)->checks())->firstWhere('label', 'A queue carries the email, not the visitor');

        config(['gadya-cms.mail.shared' => true, 'queue.default' => 'sync']);
        $this->pair();
        app()->forgetInstance(SharedSender::class);

        $this->assertSame(InstallAudit::TODO, $check()['status']);

        config(['queue.default' => 'database']);

        $this->assertSame(InstallAudit::OK, $check()['status']);
    }

    public function test_the_dns_checklist_says_who_sends_the_email_instead_of_asking_for_spf(): void
    {
        config([
            'gadya-cms.mail.shared' => true,
            'gadya-cms.seo.domains' => ['acmedental.test'],
            'app.url' => 'https://acmedental.test',
        ]);
        $this->pair();

        Http::fake(['dns.google/*' => Http::response(['Answer' => []])]);

        $records = collect(app(SiteSetup::class)->records('acmedental.test'));
        $spf = $records->firstWhere('value', fn (string $value): bool => str_contains($value, 'v=spf1'));
        $dmarc = $records->first(fn (array $record): bool => $record['name'] === '_dmarc');

        $this->assertNull($spf, 'A domain that does not send needs no SPF record.');
        $this->assertNotNull($dmarc, 'DMARC still belongs on the client\'s own domain.');
        $this->assertStringContainsString('sent by Gadya Media', $dmarc['why']);
    }
}
