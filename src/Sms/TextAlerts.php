<?php

namespace Gadya\Cms\Sms;

use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\MergeTags;
use Gadya\Cms\Jobs\SendTextAlert;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Options\Options;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Text alerts to the client's own staff when an enquiry arrives, through
 * the client's own Twilio account.
 *
 * Each number is remembered: the first text it is sent ends with "Reply
 * STOP to opt out", and a number whose owner replied STOP (Twilio refuses
 * it with error 21610) is marked opted out and never tried again - the
 * admin shows it, and only its owner texting START to the Twilio number
 * brings it back.
 */
class TextAlerts
{
    public const OPTED_OUT_ERROR = 21610;

    public const STOP_LINE = 'Reply STOP to opt out.';

    public function __construct(
        private readonly TwilioSettings $twilio,
        private readonly Options $options,
    ) {}

    public function enabled(): bool
    {
        return $this->twilio->isConfigured();
    }

    /**
     * Queue a text to each number. Never throws: an alert that cannot be
     * sent must not reach the visitor who sent the enquiry.
     *
     * @param  array<array-key, mixed>  $numbers
     */
    public function queue(array $numbers, string $message, ?int $submissionId = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $queued = 0;

        foreach (PhoneNumbers::normaliseAll($numbers) as $number) {
            if ($this->isOptedOut($number)) {
                continue;
            }

            rescue(fn () => SendTextAlert::dispatch($number, $message, $submissionId), report: true);
            $queued++;
        }

        return $queued;
    }

    /**
     * "New Catering order enquiry from Pat Jones: We need food for forty…
     * https://.../admin/submissions"
     */
    public function messageFor(FormSubmission $submission): string
    {
        $label = FormDefinition::labels()[$submission->form] ?? Str::headline((string) $submission->form);
        $said = $submission->answerOfType(['long_text'])
            ?? collect($submission->data ?? [])->map(fn ($value): string => MergeTags::text($value))->implode(' · ');
        $said = trim((string) preg_replace('/\s+/', ' ', $said));
        $link = rescue(fn (): string => SubmissionResource::getUrl(panel: (string) config('gadya-cms.panel', 'admin')), url('/'), report: false);

        return "New {$label} enquiry from {$submission->sender()}: ".Str::limit($said, 140, '…').' '.$link;
    }

    /**
     * Send one text now. Returns what Twilio said; throws when it is worth
     * trying again (Twilio unreachable, or a 5xx).
     */
    public function send(string $number, string $message): Response
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Texting is not set up.');
        }

        $body = $this->needsStopLine($number) ? $message."\n\n".self::STOP_LINE : $message;

        $response = Http::asForm()
            ->withBasicAuth((string) $this->twilio->accountSid(), (string) $this->twilio->authToken())
            ->timeout(15)
            ->post($this->twilio->messagesUrl(), array_filter([
                'To' => $number,
                'Body' => Str::limit($body, 1500, ''),
                'From' => $this->twilio->messagingServiceSid() === null ? $this->twilio->from() : null,
                'MessagingServiceSid' => $this->twilio->messagingServiceSid(),
            ]));

        if ($response->successful()) {
            $this->remember($number, ['introduced_at' => $this->state($number)['introduced_at'] ?? now()->toIso8601String(), 'last_sent_at' => now()->toIso8601String(), 'last_error' => null]);

            return $response;
        }

        if ((int) $response->json('code') === self::OPTED_OUT_ERROR) {
            $this->remember($number, ['opted_out_at' => now()->toIso8601String(), 'last_error' => 'Replied STOP']);

            return $response;
        }

        if ($response->serverError()) {
            throw new RuntimeException('Twilio did not take the text (HTTP '.$response->status().').');
        }

        $this->remember($number, ['last_error' => Str::limit((string) ($response->json('message') ?: 'HTTP '.$response->status()), 200)]);

        return $response;
    }

    /**
     * For "Send a test text": the error in plain words, or null when it
     * went.
     */
    public function test(string $number): ?string
    {
        $number = PhoneNumbers::normalise($number);

        if ($number === null) {
            return 'That does not look like a mobile number.';
        }

        try {
            $response = $this->send($number, 'This is a test from your website, '.config('gadya-cms.brand.name', config('app.name')).'. Enquiry alerts will look like this.');
        } catch (Throwable $exception) {
            return $exception->getMessage();
        }

        return $response->successful() ? null : (string) ($response->json('message') ?: 'Twilio said no (HTTP '.$response->status().').');
    }

    public function isOptedOut(string $number): bool
    {
        return filled($this->state($number)['opted_out_at'] ?? null);
    }

    /**
     * @return array{introduced_at?: string|null, last_sent_at?: string|null, opted_out_at?: string|null, last_error?: string|null}
     */
    public function state(string $number): array
    {
        $numbers = (array) $this->options->get('sms.numbers', []);

        return is_array($numbers[$number] ?? null) ? $numbers[$number] : [];
    }

    /** A number its owner has opted back in, with START to the Twilio number. */
    public function optBackIn(string $number): void
    {
        $this->remember($number, ['opted_out_at' => null, 'last_error' => null]);
    }

    private function needsStopLine(string $number): bool
    {
        return blank($this->state($number)['introduced_at'] ?? null);
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    private function remember(string $number, array $changes): void
    {
        $numbers = (array) $this->options->get('sms.numbers', []);
        $numbers[$number] = [...(is_array($numbers[$number] ?? null) ? $numbers[$number] : []), ...$changes];

        $this->options->set('sms.numbers', $numbers);
    }
}
