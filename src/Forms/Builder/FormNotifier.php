<?php

namespace Gadya\Cms\Forms\Builder;

use Filament\Actions\Action;
use Filament\Notifications\Notification as PanelNotification;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\MergeTags;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Sms\PhoneNumbers;
use Gadya\Cms\Sms\TextAlerts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Everyone who hears about an enquiry through a built form: the staff it
 * is emailed and texted to (the form's list, and the rules that send some
 * enquiries elsewhere), the person who sent it, and the panel's bell.
 * Each is rescued on its own, so one that fails never stops the others -
 * and none of them can reach the visitor.
 */
class FormNotifier
{
    public function __construct(
        private readonly FormLogic $logic,
        private readonly TextAlerts $texts,
    ) {}

    public function send(Form $form, FormSubmission $submission): void
    {
        ['emails' => $emails, 'sms' => $sms] = $this->recipients($form, $submission);

        if ($emails !== []) {
            rescue(fn () => Notification::route('mail', $emails)->notify($this->staffEmail($form, $submission, $emails)), report: true);
        }

        if ($sms !== []) {
            rescue(fn () => $this->texts->queue($sms, $this->texts->messageFor($submission), $submission->getKey()), report: true);
        }

        $this->reply($form, $submission);
        $this->bell($form, $submission);
    }

    /**
     * The email staff get, built once here so a test sent from the panel
     * is the very same message a real enquiry produces.
     *
     * @param  list<string>  $emails
     */
    public function staffEmail(Form $form, FormSubmission $submission, array $emails, bool $test = false): FormSubmitted
    {
        return new FormSubmitted(
            $submission,
            $this->definition($form, $emails),
            MergeTags::render((string) ($form->setting('email_subject') ?: 'New {form} enquiry from {name}'), $submission),
            MergeTags::render((string) $form->setting('email_body', ''), $submission),
            $test,
        );
    }

    /**
     * What the reply to the visitor says, as the form has it set.
     *
     * @return array{enabled: bool, subject: string, body: string}
     */
    public function replySettings(Form $form): array
    {
        return [
            'enabled' => (bool) $form->setting('autoreply.enabled'),
            'subject' => (string) ($form->setting('autoreply.subject') ?: 'Thank you for getting in touch with {business}'),
            'body' => (string) ($form->setting('autoreply.body') ?: Form::defaultSettings()['autoreply']['body']),
        ];
    }

    /**
     * The form's own list, plus the addresses and numbers of every rule
     * the enquiry matches - or only theirs, for a rule that says so.
     *
     * @return array{emails: list<string>, sms: list<string>}
     */
    public function recipients(Form $form, FormSubmission $submission): array
    {
        ['emails' => $emails, 'sms' => $sms] = $this->resolve($form, $submission);

        return ['emails' => $emails, 'sms' => $sms];
    }

    /**
     * The same, and which rules (by their place in the form's list, from
     * 0) sent the enquiry somewhere - for a test to say what fired.
     *
     * @return array{emails: list<string>, sms: list<string>, fired: list<int>}
     */
    public function resolve(Form $form, FormSubmission $submission): array
    {
        $emails = (array) $form->setting('notify', []);
        $sms = (array) $form->setting('notify_sms', []);
        $schema = $form->schema();
        $fired = [];
        $position = -1;

        foreach (array_values((array) $form->setting('routes', [])) as $route) {
            $position++;

            if (! is_array($route) || ! is_string($route['field'] ?? null) || ! in_array($route['operator'] ?? null, FormLogic::OPERATORS, true)) {
                continue;
            }

            $rule = ['field' => $route['field'], 'operator' => $route['operator'], 'value' => (string) ($route['value'] ?? '')];

            if (! $this->logic->passes($rule, ($submission->data ?? [])[$route['field']] ?? null, $schema->field($route['field']))) {
                continue;
            }

            $fired[] = $position;

            if ($route['instead'] ?? false) {
                $emails = [];
                $sms = [];
            }

            $emails = [...$emails, ...(array) ($route['emails'] ?? [])];
            $sms = [...$sms, ...(array) ($route['sms'] ?? [])];

            if ($route['instead'] ?? false) {
                break;
            }
        }

        return [
            'emails' => array_values(array_unique(array_filter(
                array_map(fn ($address): string => strtolower(trim((string) $address)), $emails),
                fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
            ))),
            'sms' => PhoneNumbers::normaliseAll($sms),
            'fired' => $fired,
        ];
    }

    private function reply(Form $form, FormSubmission $submission): void
    {
        $email = $submission->answerOfType(['email']);

        if (! $form->setting('autoreply.enabled') || $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        rescue(fn () => Notification::route('mail', $email)->notify(new FormAutoReply($submission, $this->replySettings($form))), report: true);
    }

    /** The panel's bell, for everyone who reads enquiries. */
    private function bell(Form $form, FormSubmission $submission): void
    {
        if (! GadyaCmsPlugin::hasNotificationsTable()) {
            return;
        }

        rescue(function () use ($form, $submission): void {
            $model = config('auth.providers.users.model');

            if (! is_string($model) || ! class_exists($model)) {
                return;
            }

            $readers = $model::query()->limit(200)->get()->filter(
                fn (Model $user): bool => method_exists($user, 'notify') && $user->can(Abilities::gate(Abilities::ENQUIRIES)),
            );

            if ($readers->isEmpty()) {
                return;
            }

            PanelNotification::make()
                ->title('New '.$form->title.' enquiry from '.$submission->sender())
                ->body(Str::limit((string) ($submission->answerOfType(['long_text']) ?? ''), 160))
                ->icon('heroicon-o-inbox-arrow-down')
                ->actions([Action::make('open')->label('Open the inbox')->url(SubmissionResource::getUrl(panel: (string) config('gadya-cms.panel', 'admin')))])
                ->sendToDatabase($readers);
        }, report: true);
    }

    /**
     * @param  list<string>  $emails
     */
    private function definition(Form $form, array $emails): FormDefinition
    {
        return new FormDefinition(
            name: $form->slug,
            label: $form->title,
            rules: [],
            notify: $emails,
            success: $form->message('success'),
            analyticsEvent: $form->setting('analytics_event'),
        );
    }
}
