<?php

namespace Gadya\Cms\Tests\Feature;

use Gadya\Cms\Analytics\AnalyticsExport;
use Gadya\Cms\Filament\Pages\Dashboard;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Notifications\AnalyticsDigest;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\SiteContext;
use Gadya\Cms\Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

class AnalyticsExtrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->publishDocument();
        Notification::fake();
    }

    private function recordVisit(string $path, string $referrer = 'google.com'): void
    {
        PageView::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'path' => $path,
            'visitor_hash' => str_repeat('a', 64),
            'referrer_host' => $referrer,
            'device_category' => 'phone',
            'viewed_at' => now()->subHour(),
        ]);
    }

    public function test_the_weekly_email_says_how_the_site_did(): void
    {
        $this->recordVisit('/about');
        $this->recordVisit('/about');

        $mail = (new AnalyticsDigest(7))->toMail($this->editor());

        $text = implode("\n", $mail->introLines);

        $this->assertStringContainsString('the last 7 days', $mail->subject);
        $this->assertStringContainsString('**1** people made **2** visits', $text);
        $this->assertStringContainsString('/about: 2 visits', $text);
        $this->assertStringContainsString('google.com: 1 people', $text);
    }

    public function test_the_dashboard_can_sign_people_up_for_the_weekly_email_and_the_command_sends_it(): void
    {
        Livewire::actingAs($this->editor())
            ->test(Dashboard::class)
            ->callAction('digest', ['recipients' => "owner@example.com\nnot-an-email"])
            ->assertHasActionErrors();

        Livewire::actingAs($this->editor())
            ->test(Dashboard::class)
            ->callAction('digest', ['recipients' => "owner@example.com, second@example.com\n"])
            ->assertNotified('Weekly email set up');

        $this->assertSame(['owner@example.com', 'second@example.com'], app(Options::class)->get('analytics.digest_recipients'));

        $this->artisan('gadya-cms:analytics-digest')->assertSuccessful();

        Notification::assertSentOnDemand(AnalyticsDigest::class, fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === ['owner@example.com', 'second@example.com']);
    }

    public function test_nothing_is_sent_when_nobody_asked(): void
    {
        $this->artisan('gadya-cms:analytics-digest')->expectsOutputToContain('nothing sent')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_dashboard_emails_the_current_person_on_request(): void
    {
        $editor = $this->editor();

        Livewire::actingAs($editor)
            ->test(Dashboard::class)
            ->callAction('emailMe')
            ->assertNotified('On its way');

        Notification::assertSentTo($editor, AnalyticsDigest::class);
    }

    public function test_the_visits_download_is_a_spreadsheet_without_visitor_identifiers(): void
    {
        $this->recordVisit('/pricing', 'bing.com');

        $response = app(AnalyticsExport::class)->download(7);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Date,Time,Page,Referrer', $csv);
        $this->assertStringContainsString('/pricing,bing.com', $csv);
        $this->assertStringNotContainsString(str_repeat('a', 64), $csv);
    }
}
