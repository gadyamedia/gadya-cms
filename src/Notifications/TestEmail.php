<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Mail\PortalTransport;
use Gadya\Cms\Mail\SenderPanel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/**
 * Proof that the site can send email, sent from the panel to whoever asked
 * for it. Not queued: the point is to see the answer, including the
 * failure, while still looking at the screen.
 */
class TestEmail extends Notification
{
    public function __construct(public readonly string $sentBy) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader(PortalTransport::PURPOSE_HEADER, 'test');
            })
            ->subject('Test email from '.config('gadya-cms.brand.name', config('app.name')))
            ->greeting('It works.')
            ->line('This is the test email '.$this->sentBy.' asked for from the admin, which means the site can send email - enquiry notifications, replies to people who fill in a form, and invitations to the panel.')
            ->line('It was sent from '.app(SenderPanel::class)->fromLine().'.')
            ->salutation('');
    }
}
