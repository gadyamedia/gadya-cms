<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Forms\AutoReplies;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\Concerns\CanBeATest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The reply that goes back to whoever filled the form in, in the client's
 * own words.
 */
class FormAutoReply extends Notification implements ShouldQueue
{
    use CanBeATest;
    use Queueable;

    /**
     * @param  array{enabled: bool, subject: string, body: string}  $reply
     */
    public function __construct(
        public readonly FormSubmission $submission,
        public readonly array $reply,
        public readonly bool $test = false,
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
        $rendered = app(AutoReplies::class)->render($this->reply['body'], $this->reply['subject'], $this->submission);

        $mail = $this->markTest((new MailMessage)->subject($this->testSubject($rendered['subject'])));

        if ($this->test) {
            $mail->line(self::testBanner());
        }

        foreach ($rendered['lines'] as $line) {
            $mail->line($line);
        }

        return $mail->salutation('');
    }
}
