<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Models\Form;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link back to a half-filled form, for someone who asked to finish it
 * later. The link is the only key: it is signed, it expires, and it stops
 * working once the form is sent.
 */
class FormResumeLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Form $form,
        public readonly string $url,
        public readonly int $days,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $business = (string) config('gadya-cms.brand.name', config('app.name'));

        return (new MailMessage)
            ->subject("Finish your {$this->form->title} form for {$business}")
            ->greeting('Hello,')
            ->line("You asked us to keep your {$this->form->title} form so you can finish it later. Everything you typed is waiting for you.")
            ->action('Finish the form', $this->url)
            ->line('The link works for '.($this->days === 1 ? 'a day' : "{$this->days} days").'. If you did not ask for it, you can ignore this email.');
    }
}
