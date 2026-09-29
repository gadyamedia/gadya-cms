<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Models\FormSubmission;
use Illuminate\Support\Str;

/**
 * The words a client writes into her emails - `{name}`, `{business}`,
 * `{form}`, `{all_answers}` or the name of any question - filled in from
 * one enquiry. `{{ name }}`, the older way of writing it, works too.
 * A tag for something the form did not ask is left out, never shown as
 * an empty bracket.
 */
final class MergeTags
{
    public static function render(string $text, FormSubmission $submission): string
    {
        $values = self::values($submission);

        return trim((string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}|\{([a-z0-9_]+)\}/i',
            fn (array $match): string => (string) ($values[Str::lower(($match[1] ?? '') !== '' ? $match[1] : $match[2])] ?? ''),
            $text,
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function values(FormSubmission $submission): array
    {
        $answers = array_map(fn ($value): string => self::text($value), $submission->data ?? []);
        $labels = $submission->fieldLabels();

        return [
            ...$answers,
            'name' => $submission->sender(),
            'business' => (string) config('gadya-cms.brand.name', config('app.name')),
            'form' => FormDefinition::labels()[$submission->form] ?? Str::headline((string) $submission->form),
            'all_answers' => collect($answers)->map(fn (string $value, string $key): string => ($labels[$key] ?? Str::headline($key)).': '.$value)->implode("\n"),
        ];
    }

    public static function text(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map(fn ($one): string => is_scalar($one) ? (string) $one : '', $value)) : (string) $value;
    }
}
