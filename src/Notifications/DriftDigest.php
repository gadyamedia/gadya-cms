<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Quality\Drift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The short email that says what has gone out of date, and nothing else.
 * Sent only when there is something to say, so it never becomes another
 * newsletter to ignore.
 */
class DriftDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $findings = app(Drift::class)->findings();
        $site = (string) config('gadya-cms.brand.name', config('app.name'));

        $mail = (new MailMessage)
            ->subject($findings->count() === 1
                ? "{$site}: one thing to look at"
                : "{$site}: {$findings->count()} things to look at")
            ->greeting('A short list, in order of how much it matters.');

        foreach ($findings as $finding) {
            $mail->line("**{$finding['says']}**");
            $mail->line($finding['does']);
        }

        return $mail
            ->action('Open the admin', (string) config('app.url'))
            ->line('Nothing here is urgent unless it says so. We watch the site for you; this is only what needs you.');
    }
}
