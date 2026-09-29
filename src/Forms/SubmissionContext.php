<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Str;

/**
 * Everything about an enquiry that is not an answer: where the visitor
 * came from, the page they sent it from, their language, the brand they
 * were on, and what they consented to - read back from the enquiry
 * itself, so a destination sees exactly what the inbox shows.
 *
 * A destination's mapping reaches it through `@` tokens:
 *
 * - `@utm_source` ... `@utm_content`, `@gclid`, `@fbclid`, `@msclkid`,
 *   `@referrer`, `@landing_page`, `@page_url`, `@locale`, `@site`,
 *   `@user_agent`, `@ip`
 * - `@source` - the campaign's source, else the ad network, else the
 *   referring site, else "direct"
 * - `@consent.given`, `@consent.text`, `@consent.at`, `@consent.ip`,
 *   `@consent.policy_version`, `@consent.policy_url` - the first consent
 *   question ticked; `@consent.{key}.at` and so on for a particular one
 * - `@name`, `@email`, `@phone`, `@message` - the answer to that kind of
 *   question, whatever the question is called
 * - `@form`, `@form_title`, `@submission_id`, `@submitted_at`,
 *   `@answers` (every answer as text), `@attribution` (all of the above
 *   as an array)
 */
final class SubmissionContext
{
    /**
     * @param  array<string, string>  $attribution
     * @param  array<string, array<string, mixed>>  $consents
     */
    public function __construct(
        public readonly FormSubmission $submission,
        public readonly array $attribution = [],
        public readonly array $consents = [],
        public readonly ?string $formTitle = null,
    ) {}

    public static function fromSubmission(FormSubmission $submission, ?string $formTitle = null): self
    {
        $meta = is_array($submission->meta) ? $submission->meta : [];
        $consents = array_filter((array) ($meta['consents'] ?? []), 'is_array');

        /* A configured call-back form keeps its one consent on the row itself. */
        if ($consents === [] && is_array($submission->consent) && filled($submission->consent['text'] ?? null)) {
            $consents = ['consent' => $submission->consent];
        }

        return new self(
            submission: $submission,
            attribution: array_filter((array) ($meta['attribution'] ?? []), 'is_string'),
            consents: $consents,
            formTitle: $formTitle,
        );
    }

    public function locale(): ?string
    {
        return $this->attribution['locale'] ?? (is_string($this->submission->meta['locale'] ?? null) ? $this->submission->meta['locale'] : null);
    }

    public function site(): ?string
    {
        return $this->attribution['site'] ?? null;
    }

    public function source(): string
    {
        return Attribution::source($this->attribution);
    }

    /**
     * The value behind a token, without its `@`: `utm_source`,
     * `consent.policy_version`. Null for one that means nothing here.
     */
    public function token(string $token): mixed
    {
        $token = ltrim(trim($token), '@');
        $submission = $this->submission;

        if (str_starts_with($token, 'consent')) {
            return $this->consentToken(Str::after($token, 'consent'));
        }

        return match ($token) {
            'source' => $this->source(),
            'locale' => $this->locale(),
            'site' => $this->site(),
            'form' => $submission->form,
            'form_title' => $this->formTitle ?? FormDefinition::labels()[$submission->form] ?? Str::headline((string) $submission->form),
            'submission_id' => $submission->getKey(),
            'submitted_at' => ($submission->created_at ?? now())->toIso8601String(),
            'answers' => MergeTags::values($submission)['all_answers'],
            'attribution' => $this->attribution,
            'name' => $submission->answerOfType(['name']) ?? $this->answer(['name', 'full_name']),
            'email' => $submission->answerOfType(['email']) ?? $this->answer(['email', 'email_address']),
            'phone' => $submission->answerOfType(['phone']) ?? $this->answer(['phone', 'telephone', 'tel', 'mobile']),
            'message' => $this->answer(['message', 'enquiry', 'inquiry', 'comments', 'details']) ?? $submission->answerOfType(['long_text']),
            default => $this->attribution[$token] ?? null,
        };
    }

    /**
     * Every token there is, for the panel's help text and the converter.
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return [
            ...array_map(fn (string $parameter): string => '@'.$parameter, Attribution::PARAMETERS),
            '@source', '@referrer', '@landing_page', '@page_url', '@locale', '@site', '@user_agent', '@ip',
            '@consent.given', '@consent.text', '@consent.at', '@consent.ip', '@consent.policy_version', '@consent.policy_url',
            '@name', '@email', '@phone', '@message',
            '@form', '@form_title', '@submission_id', '@submitted_at', '@answers', '@attribution',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'attribution' => $this->attribution,
            'consents' => $this->consents,
            'locale' => $this->locale(),
            'site' => $this->site(),
            'source' => $this->source(),
        ];
    }

    private function consentToken(string $rest): mixed
    {
        $parts = array_values(array_filter(explode('.', $rest), fn (string $part): bool => $part !== ''));

        /* `@consent.ok.at` names the question; `@consent.at` means the first one ticked. */
        if (count($parts) === 2 || (count($parts) === 1 && isset($this->consents[$parts[0]]) && ! in_array($parts[0], ['given', 'text', 'at', 'ip', 'policy_version', 'policy_url'], true))) {
            $record = $this->consents[$parts[0]] ?? null;
            $part = $parts[1] ?? 'given';
        } else {
            $record = $this->consents === [] ? null : $this->consents[array_key_first($this->consents)];
            $part = $parts[0] ?? 'given';
        }

        if ($part === 'given') {
            return is_array($record);
        }

        return is_array($record) ? ($record[$part] ?? null) : null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function answer(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = ($this->submission->data ?? [])[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
