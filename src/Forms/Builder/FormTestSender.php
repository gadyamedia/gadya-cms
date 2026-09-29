<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\AutoReplies;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Mail\SenderPanel;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Notifications\FormAutoReply;
use Gadya\Cms\Notifications\FormSubmitted;
use Gadya\Cms\Notifications\TestEmail;
use Gadya\Cms\Sms\PhoneNumbers;
use Gadya\Cms\Sms\TextAlerts;
use Illuminate\Notifications\Notification as MailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Send a test" for a form's notifications: the real staff email, the
 * reply to the visitor and the text alert, built by the very code a real
 * enquiry uses - same recipients, subject, body, routing and sender -
 * from sample answers, and marked as a test.
 *
 * Nothing is kept. There is no submission row, no FormSubmitted event, no
 * push to the portal, no webhook and no destination: the sample enquiry
 * is a model that is never saved. Sent now, not queued, so the answer -
 * including a failure - is on screen while someone is looking at it.
 *
 * @phpstan-type Choices array{to?: string, address?: string|null, me?: string|null, rule?: int|string|null, reply?: bool, text?: bool, numbers?: array<array-key, mixed>}
 */
class FormTestSender
{
    public const EVERYONE = 'all';

    public const ONLY_ME = 'me';

    public const THIS_ADDRESS = 'address';

    private const VISITOR = 'sam.sample@example.com';

    public function __construct(
        private readonly FormNotifier $notifier,
        private readonly TextAlerts $texts,
        private readonly AutoReplies $replies,
        private readonly SenderPanel $panel,
    ) {}

    /**
     * A form built in the panel, as it stands on screen - saved or not.
     *
     * @param  Choices  $choices
     */
    public function built(Form $form, array $choices): TestReport
    {
        $report = new TestReport;
        $routes = array_values((array) $form->setting('routes', []));
        $rule = $this->ruleChosen($choices, $routes);
        $submission = $this->sampleBuilt($form, $rule === null ? null : $routes[$rule]);

        $resolved = $this->notifier->resolve($form, $submission);

        if ($rule !== null) {
            $report->said('Pretended a visitor answered so that "'.$this->describeRule($form, $routes[$rule]).'" is true; '.$this->firedWords($form, $routes, $resolved['fired']));
        }

        $to = $this->recipients($choices, $resolved['emails']);

        $this->deliver($report, $to, $this->notifier->staffEmail($form, $submission, $to, test: true), 'The email to your team');

        if ($choices['reply'] ?? false) {
            $reply = $this->notifier->replySettings($form);

            $this->deliverReply($report, $choices, new FormAutoReply($submission, $reply, test: true), $reply['enabled']);
        }

        if ($choices['text'] ?? false) {
            /* Only the real list is texted when the real list is being tested. */
            $typed = PhoneNumbers::normaliseAll((array) ($choices['numbers'] ?? []));

            $this->text($report, $submission, $typed !== [] ? $typed : (($choices['to'] ?? self::EVERYONE) === self::EVERYONE ? $resolved['sms'] : []));
        }

        return $report;
    }

    /**
     * The generic "Send me a test email": proof the site can send at all.
     */
    public function generic(string $address, string $sentBy): TestReport
    {
        $report = new TestReport;

        $this->send($report, [$address], new TestEmail($sentBy), 'The test email');

        return $report;
    }

    /**
     * A form configured in code, with the addresses and reply as they
     * are on the Enquiry emails screen right now.
     *
     * @param  array<string, mixed>  $state  The screen's own `data`: notify.{form} and replies.{form}
     * @param  Choices  $choices
     */
    public function configured(string $name, array $state, array $choices): TestReport
    {
        $report = new TestReport;
        $definition = FormDefinition::find($name);

        if ($definition === null) {
            return $report->wentWrong('That form is no longer in the site\'s settings.');
        }

        $configured = (array) config("gadya-cms.forms.forms.{$name}.notify", []);
        $chosen = (array) data_get($state, "notify.{$name}", FormDefinition::chosenRecipients($name));
        $listed = $this->validAddresses([...$configured, ...$chosen]);
        $to = $this->recipients($choices, $listed);

        $submission = $this->sampleConfigured($definition);

        $this->deliver($report, $to, new FormSubmitted($submission, new FormDefinition($name, $definition->label, $definition->rules, $to, $definition->success, $definition->analyticsEvent), null, null, true), 'The email to your team');

        if ($choices['reply'] ?? false) {
            $saved = $this->replies->all()[$name] ?? ['enabled' => false, 'subject' => $this->replies->defaultSubject(), 'body' => $this->replies->defaultBody()];
            $reply = [
                'enabled' => (bool) data_get($state, "replies.{$name}.enabled", $saved['enabled']),
                'subject' => (string) (data_get($state, "replies.{$name}.subject") ?: $saved['subject']),
                'body' => (string) (data_get($state, "replies.{$name}.body") ?: $saved['body']),
            ];

            $this->deliverReply($report, $choices, new FormAutoReply($submission, $reply, test: true), $reply['enabled']);
        }

        return $report;
    }

    /**
     * Who the staff email goes to, for the mode chosen. Everyone is the
     * real list, so the test proves the real list.
     *
     * @param  Choices  $choices
     * @param  list<string>  $listed
     * @return list<string>
     */
    public function recipients(array $choices, array $listed): array
    {
        return match ($choices['to'] ?? self::EVERYONE) {
            self::ONLY_ME => $this->validAddresses([$choices['me'] ?? '']),
            self::THIS_ADDRESS => $this->validAddresses([$choices['address'] ?? '']),
            default => $listed,
        };
    }

    /**
     * @param  list<string>  $to
     */
    private function deliver(TestReport $report, array $to, MailNotification $notification, string $what): void
    {
        if ($to === []) {
            $report->wentWrong("{$what} was not sent: there is nobody to send it to. Add an address to the list, or choose \"Send only to me\".");

            return;
        }

        $this->send($report, $to, $notification, $what);
    }

    /**
     * The reply to the visitor goes to the address chosen - never to a
     * real visitor: the one given, or else whoever is testing.
     *
     * @param  Choices  $choices
     */
    private function deliverReply(TestReport $report, array $choices, FormAutoReply $notification, bool $switchedOn): void
    {
        $address = ($choices['to'] ?? self::EVERYONE) === self::THIS_ADDRESS ? ($choices['address'] ?? '') : ($choices['me'] ?? '');
        $to = $this->validAddresses([$address]);

        if ($to === []) {
            $report->wentWrong('The reply to the visitor was not sent: there is no address to send it to.');

            return;
        }

        $this->send($report, $to, $notification, 'The reply to the visitor');

        if (! $switchedOn) {
            $report->said('That reply is switched off, so visitors are not sent it yet. Turn on "Send a reply" to start.');
        }
    }

    /**
     * @param  list<string>  $to
     */
    private function send(TestReport $report, array $to, MailNotification $notification, string $what): void
    {
        try {
            Notification::route('mail', $to)->notifyNow($notification);
        } catch (Throwable $exception) {
            $reason = trim($exception->getMessage()) ?: 'The mail service gave no reason.';

            $report->wentWrong("{$what} was not sent: {$reason} ".$this->panel->fixFor($reason));

            return;
        }

        $report->said($this->panel->goesNowhere()
            ? "{$what} was written to the site's log, not sent - this site's mailer is \"".config('mail.default').'", which does not deliver email. It would have gone to '.implode(', ', $to).'.'
            : "{$what} was sent to ".implode(', ', $to)." from '".$this->panel->fromLine()."'. It can take a few minutes; check junk too.");
    }

    /**
     * Each number's state, as the text code reports it.
     *
     * @param  array<array-key, mixed>  $numbers
     */
    private function text(TestReport $report, FormSubmission $submission, array $numbers): void
    {
        if (! $this->texts->enabled()) {
            $report->wentWrong('The text was not sent: texting is not set up. Add your Twilio details under Settings → Text messages.');

            return;
        }

        $numbers = PhoneNumbers::normaliseAll($numbers);

        if ($numbers === []) {
            $report->wentWrong('The text was not sent: there is no mobile number to send it to.');

            return;
        }

        $message = '[Test] '.$this->texts->messageFor($submission);

        foreach ($numbers as $number) {
            $shown = PhoneNumbers::display($number);

            if ($this->texts->isOptedOut($number)) {
                $report->wentWrong("Text to {$shown}: not sent - they replied STOP. They get no texts until they text START to your Twilio number.");

                continue;
            }

            try {
                $response = $this->texts->send($number, $message);
            } catch (Throwable $exception) {
                $report->wentWrong("Text to {$shown}: failed - {$exception->getMessage()}");

                continue;
            }

            $state = $this->texts->state($number);

            match (true) {
                $response->successful() => $report->said("Text to {$shown}: sent."),
                filled($state['opted_out_at'] ?? null) => $report->wentWrong("Text to {$shown}: not sent - they replied STOP. They get no texts until they text START to your Twilio number."),
                default => $report->wentWrong("Text to {$shown}: failed - ".($state['last_error'] ?? 'Twilio said no').'.'),
            };
        }
    }

    /**
     * An enquiry that was never written down, from sample answers. When a
     * routing rule is being tried, its own question gets the answer that
     * makes it true.
     *
     * @param  array<string, mixed>|null  $route
     */
    private function sampleBuilt(Form $form, ?array $route): FormSubmission
    {
        $schema = $form->schema();
        $data = [];

        foreach ($schema->inputs() as $field) {
            $type = $schema->type($field);

            if ($type === null || $type->isFile()) {
                continue;
            }

            $options = array_values((array) ($field['options'] ?? []));

            $data[$field['key']] = match (true) {
                $type->hasChoices() && $options !== [] => $type->isMultiple() ? [$options[0]['label']] : $options[0]['label'],
                $field['type'] === 'name' => 'Sam Sample',
                $field['type'] === 'email' => self::VISITOR,
                $field['type'] === 'phone' => '555 0100',
                $field['type'] === 'long_text' => 'This is a sample message, so you can see how the email will look.',
                in_array($field['type'], ['number', 'money', 'rating', 'scale', 'slider'], true) => '3',
                in_array($field['type'], ['checkbox', 'consent', 'mailing_list', 'yes_no'], true) => 'yes',
                default => 'Sample answer',
            };
        }

        if ($route !== null && is_string($route['field'] ?? null)) {
            $data[$route['field']] = $this->answerThatMatches((string) ($route['operator'] ?? 'equals'), (string) ($route['value'] ?? ''), (array) $schema->field($route['field']));
        }

        return $this->unsaved($form->slug, $data, [
            'types' => array_intersect_key($schema->types(), $data),
            'labels' => array_intersect_key($schema->labels(), $data),
        ]);
    }

    private function sampleConfigured(FormDefinition $definition): FormSubmission
    {
        $data = [];

        foreach ($definition->fields() as $key) {
            $data[$key] = match (true) {
                str_contains($key, 'email') => self::VISITOR,
                str_contains($key, 'name') => 'Sam Sample',
                str_contains($key, 'phone') => '555 0100',
                in_array($key, ['message', 'details', 'comments', 'notes', 'enquiry'], true) => 'This is a sample message, so you can see how the email will look.',
                default => 'Sample answer',
            };
        }

        return $this->unsaved($definition->name, $data, []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function unsaved(string $form, array $data, array $meta): FormSubmission
    {
        $submission = new FormSubmission;
        $submission->forceFill([
            'form' => $form,
            'data' => $data,
            'meta' => $meta === [] ? null : $meta,
            'path' => '/test',
            'created_at' => now(),
        ]);

        return $submission;
    }

    /**
     * The answer that makes "field <operator> value" true.
     *
     * @param  array<string, mixed>  $field
     */
    private function answerThatMatches(string $operator, string $value, array $field): mixed
    {
        return match ($operator) {
            'not_equals', 'not_contains' => 'Something else',
            'empty' => '',
            'not_empty' => 'Sample answer',
            'greater_than' => (string) ((float) $value + 1),
            'less_than' => (string) ((float) $value - 1),
            default => in_array($field['type'] ?? null, ['checkbox', 'consent', 'mailing_list'], true) ? 'yes' : $value,
        };
    }

    /**
     * @param  Choices  $choices
     * @param  list<mixed>  $routes
     */
    private function ruleChosen(array $choices, array $routes): ?int
    {
        $rule = $choices['rule'] ?? null;

        return $rule !== null && $rule !== '' && is_numeric($rule) && isset($routes[(int) $rule]) && is_array($routes[(int) $rule]) ? (int) $rule : null;
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public function describeRule(Form $form, array $route): string
    {
        $field = (string) ($route['field'] ?? '');
        $label = $form->schema()->field($field)['label'] ?? Str::headline($field);
        $operator = FormLogic::operatorLabels()[$route['operator'] ?? 'equals'] ?? 'is';
        $value = in_array($route['operator'] ?? null, ['empty', 'not_empty'], true) ? '' : ' '.($route['value'] ?? '');

        return "{$label} {$operator}{$value}";
    }

    /**
     * @param  list<mixed>  $routes
     * @param  list<int>  $fired
     */
    private function firedWords(Form $form, array $routes, array $fired): string
    {
        if ($fired === []) {
            return 'no rule fired, so the usual list is used.';
        }

        $words = array_map(fn (int $position): string => '"'.$this->describeRule($form, (array) $routes[$position]).'"', $fired);

        return 'the rule'.(count($words) > 1 ? 's' : '').' that fired: '.implode(', ', $words).'.';
    }

    /**
     * @param  array<array-key, mixed>  $addresses
     * @return list<string>
     */
    private function validAddresses(array $addresses): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($address): string => strtolower(trim((string) $address)), $addresses),
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }
}
