<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Analytics\AnalyticsReport;
use Gadya\Cms\Filament\Pages\Dashboard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The dashboard, in an email, once a week: how many people came, what
 * they did, and where they came from. For the client who never opens the
 * admin but reads her inbox.
 */
class AnalyticsDigest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $days = 7) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $report = app(AnalyticsReport::class)->for($this->days);
        $headline = $report->headline();
        $site = (string) config('gadya-cms.brand.name', config('app.name'));

        $mail = (new MailMessage)
            ->subject("{$site}: the last {$this->days} days on the site")
            ->greeting("Here is how {$site} did over the last {$this->days} days.")
            ->line("**{$headline['visitors']}** people made **{$headline['views']}** visits.")
            ->line("**{$headline['phone_clicks']}** tapped the phone number and **{$headline['enquiries']}** started a booking or sent a form, so **{$headline['contact_rate']}%** of visitors got in touch.");

        $pages = $report->topPages(5);

        if ($pages->isNotEmpty()) {
            $mail->line('**Most visited**');

            foreach ($pages as $page) {
                $mail->line("- {$page->path}: {$page->views} visits");
            }
        }

        $referrers = $report->referrers(5);

        if ($referrers->isNotEmpty()) {
            $mail->line('**How they found you**');

            foreach ($referrers as $referrer) {
                $mail->line("- {$referrer->referrer_host}: {$referrer->visitors} people");
            }
        }

        $events = $report->events();

        if ($events->isNotEmpty()) {
            $mail->line('**What they did**');

            foreach ($events as $event) {
                $mail->line('- '.Str::headline($event->name).": {$event->total}");
            }
        }

        return $mail
            ->action('Open the dashboard', Dashboard::getUrl())
            ->salutation('Counted on your own site: no Google, no cookies.');
    }
}
