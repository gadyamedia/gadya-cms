<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\FormSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The email that goes out when someone fills in a form: every field they
 * typed, and a link to the same enquiry in the panel.
 */
class FormSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly FormSubmission $submission,
        public readonly FormDefinition $form,
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
        $mail = (new MailMessage)
            ->subject("New {$this->form->label} enquiry from {$this->submission->sender()}")
            ->greeting("Someone filled in the {$this->form->label} form.");

        foreach ($this->submission->data ?? [] as $field => $value) {
            $mail->line('**'.Str::headline((string) $field).':** '.(is_array($value) ? implode(', ', $value) : (string) $value));
        }

        $replyTo = $this->submission->data['email'] ?? null;

        if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($replyTo, $this->submission->sender());
        }

        return $mail
            ->line('Sent from '.($this->submission->path ?: 'the site').' on '.$this->submission->created_at->format('D j M Y, g:ia').'.')
            ->action('Open in the admin', SubmissionResource::getUrl())
            ->salutation('Replying to this email goes straight to them.');
    }
}
