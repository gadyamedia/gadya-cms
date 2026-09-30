<?php

namespace Gadya\Cms\Notifications;

use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\MergeTags;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\Concerns\CanBeATest;
use Gadya\Cms\Support\SiteTimezone;
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
    use CanBeATest;
    use Queueable;

    /**
     * @param  string|null  $subject  A built form's own subject line, merge tags filled in
     * @param  string|null  $intro  A built form's own words above the answers
     * @param  bool  $test  Sent from the panel to try it out: marked as one, and otherwise identical
     */
    public function __construct(
        public readonly FormSubmission $submission,
        public readonly FormDefinition $form,
        public readonly ?string $subject = null,
        public readonly ?string $intro = null,
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
        $mail = (new MailMessage)
            ->subject($this->testSubject(filled($this->subject) ? (string) $this->subject : "New {$this->form->label} enquiry from {$this->submission->sender()}"))
            ->greeting($this->test ? self::testBanner() : "Someone filled in the {$this->form->label} form.");

        foreach (preg_split('/\n{2,}/', trim((string) $this->intro)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $mail->line(trim($paragraph));
            }
        }

        $labels = $this->submission->fieldLabels();

        foreach ($this->submission->data ?? [] as $field => $value) {
            $mail->line('**'.($labels[$field] ?? Str::headline((string) $field)).':** '.MergeTags::text($value));
        }

        if (($this->submission->files ?? []) !== []) {
            $mail->line('Files they sent are attached to the enquiry in the admin.');
        }

        $replyTo = $this->submission->answerOfType(['email']) ?? ($this->submission->data['email'] ?? null);

        if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->replyTo($replyTo, $this->submission->sender());
        }

        $mail = $this->markTest($mail)
            ->line('Sent from '.($this->submission->path ?: 'the site').' on '.app(SiteTimezone::class)->format($this->submission->created_at, 'D j M Y, g:ia').'.')
            ->action('Open in the admin', SubmissionResource::getUrl())
            ->salutation('Replying to this email goes straight to them.');

        /*
         * Under Gadya's name rather than the framework's: the same words,
         * drawn by the package's own view. The message keeps every line, so
         * whatever reads it - a test, a listener - still finds them.
         */
        return config('gadya-cms.mail.branded', true)
            ? $mail->view(['gadya-cms::mail.enquiry', 'gadya-cms::mail.enquiry-text'], $mail->data())
            : $mail;
    }
}
