<?php

namespace Gadya\Cms\Notifications;

use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email that brings someone onto the team.
 *
 * It carries a password-reset token rather than a password: nobody sends a
 * password in an email, and reusing the panel's own reset flow means the
 * link expires, can only be used once, and needs no new screens.
 */
class PanelInvitation extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly ?string $invitedBy = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = (string) config('gadya-cms.brand.name', config('app.name'));
        $url = Filament::getPanel((string) config('gadya-cms.panel', 'admin'))
            ->getResetPasswordUrl($this->token, $notifiable);

        $hours = (int) config('gadya-cms.users.invitation_expires_hours', 168);

        return (new MailMessage)
            ->subject("You have been invited to help manage {$site}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->invitedBy === null
                ? "You have been invited to help manage the {$site} website."
                : "{$this->invitedBy} has invited you to help manage the {$site} website.")
            ->action('Choose your password', $url)
            ->line('Once you have set a password you can sign in and start editing.')
            ->line("This link stops working in {$this->expiryLabel($hours)}, so use it soon. If you were not expecting this, you can ignore it.");
    }

    private function expiryLabel(int $hours): string
    {
        if ($hours % 24 === 0) {
            $days = intdiv($hours, 24);

            return $days === 1 ? 'a day' : "{$days} days";
        }

        return $hours === 1 ? 'an hour' : "{$hours} hours";
    }
}
