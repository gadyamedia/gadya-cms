<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Support\Str;

/**
 * A builder form's questions, in order, and the steps they fall into.
 *
 * Stored as a plain list of fields, each an array: `key` (the name its
 * answer is kept under), `type`, `label`, `help`, `placeholder`,
 * `required`, `default`, `width` (full or half), `prefill` (take the
 * answer from `?key=` in the address), `options` (a list of `key` and
 * `label`), `rules` (the type's own settings) and `logic` (when to show
 * it). A `page_break` field starts a new step, titled by its label.
 *
 * The machinery - key, type, rules, logic, width, default - sits under
 * keys the translation overlay never touches, so a form translated into
 * Spanish can only ever change its words.
 */
final class FormSchema
{
    public const WIDTHS = ['full', 'half'];

    /** @var list<array<string, mixed>> */
    private array $fields;

    /**
     * @param  array<array-key, mixed>  $fields
     */
    public function __construct(array $fields)
    {
        $this->fields = self::normalise($fields);
    }

    /**
     * Fill in what a saved field left out, and give every field and option
     * a key. A key once given is kept, so renaming a question never loses
     * its answers or its translations.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    public static function normalise(array $fields): array
    {
        $normalised = [];
        $used = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['type'] ?? null)) {
                continue;
            }

            /* The shape Filament's Builder saves: ['type' => ..., 'data' => [...]]. */
            if (is_array($field['data'] ?? null)) {
                $field = ['type' => $field['type'], ...$field['data']];
            }

            $key = self::keyFrom((string) ($field['key'] ?? ''), (string) ($field['label'] ?? ''), $field['type']);
            $candidate = $key;
            $suffix = 2;

            while (in_array($candidate, $used, true)) {
                $candidate = $key.'_'.$suffix++;
            }

            $used[] = $candidate;

            $normalised[] = [
                ...$field,
                'key' => $candidate,
                'type' => $field['type'],
                'label' => trim((string) ($field['label'] ?? '')),
                'help' => trim((string) ($field['help'] ?? '')),
                'placeholder' => trim((string) ($field['placeholder'] ?? '')),
                'required' => (bool) ($field['required'] ?? false),
                'width' => in_array($field['width'] ?? null, self::WIDTHS, true) ? $field['width'] : 'full',
                'prefill' => (bool) ($field['prefill'] ?? ($field['type'] === 'hidden')),
                'options' => self::options((array) ($field['options'] ?? [])),
                'rules' => is_array($field['rules'] ?? null) ? $field['rules'] : [],
                'logic' => self::logic($field['logic'] ?? null),
            ];
        }

        return $normalised;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * The fields that ask for an answer.
     *
     * @return list<array<string, mixed>>
     */
    public function inputs(): array
    {
        return array_values(array_filter($this->fields, fn (array $field): bool => $this->type($field)?->isInput() ?? false));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $key): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function type(array $field): ?FieldType
    {
        return app(FieldTypes::class)->get((string) $field['type']);
    }

    /**
     * The form in steps. A form with no page break is one step with no
     * title of its own.
     *
     * @return list<array{index: int, title: string, logic: array<string, mixed>|null, fields: list<array<string, mixed>>}>
     */
    public function steps(?string $firstTitle = null): array
    {
        $steps = [['index' => 0, 'title' => (string) $firstTitle, 'logic' => null, 'fields' => []]];

        foreach ($this->fields as $field) {
            if ($field['type'] === 'page_break') {
                $steps[] = ['index' => count($steps), 'title' => $field['label'], 'logic' => $field['logic'], 'fields' => []];

                continue;
            }

            $steps[count($steps) - 1]['fields'][] = $field;
        }

        /* A break with nothing after it, or before anything, is not a step. */
        $steps = array_values(array_filter($steps, fn (array $step): bool => $step['fields'] !== []));

        if ($steps === []) {
            return [['index' => 0, 'title' => (string) $firstTitle, 'logic' => null, 'fields' => []]];
        }

        foreach ($steps as $index => $step) {
            $steps[$index]['index'] = $index;
        }

        return $steps;
    }

    public function isMultiStep(): bool
    {
        return count($this->steps()) > 1;
    }

    /** Which step each input is on. @return array<string, int> */
    public function stepOf(): array
    {
        $map = [];

        foreach ($this->steps() as $step) {
            foreach ($step['fields'] as $field) {
                $map[$field['key']] = $step['index'];
            }
        }

        return $map;
    }

    public function hasFiles(): bool
    {
        foreach ($this->inputs() as $field) {
            if ($this->type($field)?->isFile() && $field['type'] !== 'signature') {
                return true;
            }
        }

        return false;
    }

    /**
     * The first field of a kind, for lifting the sender's name, email and
     * phone out of a form whose questions are called anything.
     *
     * @param  list<string>  $types
     * @return array<string, mixed>|null
     */
    public function firstOfType(array $types): ?array
    {
        foreach ($this->inputs() as $field) {
            if (in_array($field['type'], $types, true)) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, string> key => type */
    public function types(): array
    {
        $types = [];

        foreach ($this->inputs() as $field) {
            $types[$field['key']] = (string) $field['type'];
        }

        return $types;
    }

    /** @return array<string, string> key => label, for the inbox and the export */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->inputs() as $field) {
            $labels[$field['key']] = $field['label'] !== '' ? $field['label'] : Str::headline($field['key']);
        }

        return $labels;
    }

    private static function keyFrom(string $key, string $label, string $type): string
    {
        $key = Str::of($key !== '' ? $key : $label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->trim('_')->toString();

        if ($key === '' || ! preg_match('/^[a-z]/', $key)) {
            $key = $type.($key !== '' ? '_'.$key : '');
        }

        return in_array($key, self::reserved(), true) ? $key.'_field' : $key;
    }

    /**
     * Names the form already posts for itself, which a question may not take.
     *
     * @return list<string>
     */
    public static function reserved(): array
    {
        return ['_token', '_path', '_redirect', '_t', '_step', '_started', '_resume', '_resume_email', '_save', '_validate_step', 'cf_turnstile_response', (string) config('gadya-cms.forms.honeypot', 'website')];
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $options): array
    {
        $clean = [];
        $used = [];

        foreach ($options as $option) {
            if (is_string($option)) {
                $option = ['label' => $option];
            }

            if (! is_array($option) || trim((string) ($option['label'] ?? '')) === '') {
                continue;
            }

            $label = trim((string) $option['label']);
            $key = Str::of((string) ($option['key'] ?? '') ?: $label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(60, '')->toString() ?: 'option';
            $candidate = $key;
            $suffix = 2;

            while (in_array($candidate, $used, true)) {
                $candidate = $key.'_'.$suffix++;
            }

            $used[] = $candidate;
            $clean[] = ['key' => $candidate, 'label' => $label];
        }

        return $clean;
    }

    /**
     * @return array{action: string, match: string, rules: list<array{field: string, operator: string, value: string}>}|null
     */
    private static function logic(mixed $logic): ?array
    {
        if (! is_array($logic) || ! in_array($logic['action'] ?? null, ['show', 'hide'], true)) {
            return null;
        }

        $rules = [];

        foreach ((array) ($logic['rules'] ?? []) as $rule) {
            if (is_array($rule) && is_string($rule['field'] ?? null) && $rule['field'] !== '' && in_array($rule['operator'] ?? null, FormLogic::OPERATORS, true)) {
                $rules[] = ['field' => $rule['field'], 'operator' => $rule['operator'], 'value' => trim((string) ($rule['value'] ?? ''))];
            }
        }

        if ($rules === []) {
            return null;
        }

        return [
            'action' => $logic['action'],
            'match' => ($logic['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => $rules,
        ];
    }
}
