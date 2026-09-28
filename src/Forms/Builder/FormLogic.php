<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Support\Str;

/**
 * Which questions and steps are showing, given the answers so far.
 *
 * The same rules run in the visitor's browser, to show and hide as they
 * type, and here, when the form is sent: whatever the browser did, a
 * question that was hidden is never required and its answer is never
 * kept. A question whose rule reads a hidden question reads it as empty,
 * so hiding one question can hide the ones that depend on it.
 */
class FormLogic
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty', 'greater_than', 'less_than'];

    /**
     * @return array<string, string>
     */
    public static function operatorLabels(): array
    {
        return [
            'equals' => 'is',
            'not_equals' => 'is not',
            'contains' => 'contains',
            'not_contains' => 'does not contain',
            'empty' => 'is empty',
            'not_empty' => 'is filled in',
            'greater_than' => 'is more than',
            'less_than' => 'is less than',
        ];
    }

    /**
     * The keys of every field that is showing, in order.
     *
     * @param  array<string, mixed>  $input  The answers as posted
     * @return list<string>
     */
    public function visible(FormSchema $schema, array $input): array
    {
        $visible = [];
        $values = [];

        foreach ($schema->steps() as $step) {
            $stepShown = $this->shows($step['logic'], $values, $schema);

            foreach ($step['fields'] as $field) {
                $shown = $stepShown && $this->shows($field['logic'], $values, $schema);

                if ($shown) {
                    $visible[] = $field['key'];
                }

                /* A hidden question answers every rule that reads it as empty. */
                $values[$field['key']] = $shown ? ($input[$field['key']] ?? null) : null;
            }
        }

        return $visible;
    }

    /**
     * The steps that are showing, by index.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function visibleSteps(FormSchema $schema, array $input): array
    {
        $visible = $this->visible($schema, $input);
        $steps = [];

        foreach ($schema->steps() as $step) {
            foreach ($step['fields'] as $field) {
                if (in_array($field['key'], $visible, true)) {
                    $steps[] = $step['index'];

                    break;
                }
            }
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>|null  $logic
     * @param  array<string, mixed>  $values
     */
    public function shows(?array $logic, array $values, ?FormSchema $schema = null): bool
    {
        if ($logic === null || ($logic['rules'] ?? []) === []) {
            return true;
        }

        $results = array_map(fn (array $rule): bool => $this->passes($rule, $values[$rule['field']] ?? null, $schema?->field($rule['field'])), $logic['rules']);

        $matched = ($logic['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);

        return ($logic['action'] ?? 'show') === 'show' ? $matched : ! $matched;
    }

    /**
     * One rule against one answer. A choice matches its key or its label,
     * in any case, so "Service is Catering" works however it was written.
     *
     * @param  array{field: string, operator: string, value: string}  $rule
     * @param  array<string, mixed>|null  $field
     */
    public function passes(array $rule, mixed $answer, ?array $field = null): bool
    {
        $answers = array_values(array_filter(
            array_map(fn ($one): string => $this->comparable($one, $field), is_array($answer) ? array_values($answer) : [$answer]),
            fn (string $one): bool => $one !== '',
        ));
        $expected = Str::lower(trim($rule['value']));
        $isEmpty = $answers === [];

        return match ($rule['operator']) {
            'empty' => $isEmpty,
            'not_empty' => ! $isEmpty,
            'equals' => in_array($expected, $this->withLabels($answers, $field), true),
            'not_equals' => ! in_array($expected, $this->withLabels($answers, $field), true),
            'contains' => $this->contains($answers, $field, $expected),
            'not_contains' => ! $this->contains($answers, $field, $expected),
            'greater_than' => ! $isEmpty && is_numeric($answers[0]) && is_numeric($expected) && (float) $answers[0] > (float) $expected,
            'less_than' => ! $isEmpty && is_numeric($answers[0]) && is_numeric($expected) && (float) $answers[0] < (float) $expected,
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>|null  $field
     */
    private function comparable(mixed $value, ?array $field): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : '';
        }

        if (is_array($value)) {
            return Str::lower(trim(implode(' ', array_filter(array_map(fn ($part): string => is_scalar($part) ? (string) $part : '', $value)))));
        }

        $value = Str::lower(trim(is_scalar($value) ? (string) $value : ''));

        /* A ticked box posts "1" or "on"; "yes" is what a person writes in a rule. */
        if ($field !== null && in_array($field['type'], ['checkbox', 'consent', 'mailing_list'], true)) {
            return in_array($value, ['1', 'on', 'true', 'yes'], true) ? 'yes' : '';
        }

        return $value;
    }

    /**
     * @param  list<string>  $answers
     * @param  array<string, mixed>|null  $field
     * @return list<string>
     */
    private function withLabels(array $answers, ?array $field): array
    {
        $all = $answers;

        foreach ((array) ($field['options'] ?? []) as $option) {
            if (in_array(Str::lower((string) $option['key']), $answers, true)) {
                $all[] = Str::lower((string) $option['label']);
            }
        }

        return $all;
    }

    /**
     * @param  list<string>  $answers
     * @param  array<string, mixed>|null  $field
     */
    private function contains(array $answers, ?array $field, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }

        foreach ($this->withLabels($answers, $field) as $answer) {
            if (str_contains($answer, $expected)) {
                return true;
            }
        }

        return false;
    }
}
