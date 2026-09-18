<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Options\Options;
use Illuminate\Support\Str;

/**
 * The "thank you, we have your message" email.
 *
 * Every client eventually asks for it, and every client wants it to say
 * something different, so the words live in the panel rather than in a
 * template only a developer can reach.
 */
class AutoReplies
{
    public function __construct(private readonly Options $options) {}

    /**
     * @return array<string, array{enabled: bool, subject: string, body: string}>
     */
    public function all(): array
    {
        $stored = (array) $this->options->get('forms.replies', []);
        $replies = [];

        foreach (array_keys(FormDefinition::labels()) as $form) {
            $reply = is_array($stored[$form] ?? null) ? $stored[$form] : [];

            $replies[$form] = [
                'enabled' => (bool) ($reply['enabled'] ?? false),
                'subject' => (string) ($reply['subject'] ?? $this->defaultSubject()),
                'body' => (string) ($reply['body'] ?? $this->defaultBody()),
            ];
        }

        return $replies;
    }

    /**
     * @return array{enabled: bool, subject: string, body: string}|null
     */
    public function for(string $form): ?array
    {
        $reply = $this->all()[$form] ?? null;

        return $reply !== null && $reply['enabled'] ? $reply : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $replies
     */
    public function save(array $replies): void
    {
        $clean = [];

        foreach ($replies as $form => $reply) {
            if (! is_string($form) || ! is_array($reply)) {
                continue;
            }

            $clean[$form] = [
                'enabled' => (bool) ($reply['enabled'] ?? false),
                'subject' => trim((string) ($reply['subject'] ?? '')) ?: $this->defaultSubject(),
                'body' => trim((string) ($reply['body'] ?? '')) ?: $this->defaultBody(),
            ];
        }

        $this->options->set('forms.replies', $clean);
    }

    /**
     * The email as it will arrive, with the submission's own words in it.
     * Anything the writer asks for that the form did not collect is left
     * out rather than shown as an empty bracket.
     *
     * @return array{subject: string, lines: list<string>}
     */
    public function render(string $template, string $subject, FormSubmission $submission): array
    {
        $replace = function (string $text) use ($submission): string {
            $values = [
                'name' => $submission->sender(),
                'business' => (string) config('gadya-cms.brand.name', config('app.name')),
                'form' => FormDefinition::labels()[$submission->form] ?? $submission->form,
                ...array_map(fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value, $submission->data ?? []),
            ];

            return trim((string) preg_replace_callback(
                '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
                fn (array $match): string => (string) ($values[Str::lower($match[1])] ?? ''),
                $text,
            ));
        };

        return [
            'subject' => $replace($subject),
            'lines' => collect(preg_split('/\n{2,}/', $replace($template)) ?: [])
                ->map(fn (string $line): string => trim($line))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    public function defaultSubject(): string
    {
        return 'Thank you for getting in touch with {{ business }}';
    }

    public function defaultBody(): string
    {
        return "Hello {{ name }},\n\nThank you for your message. We have it, and someone will come back to you shortly.\n\nBest wishes,\n{{ business }}";
    }
}
