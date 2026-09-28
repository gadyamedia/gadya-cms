<?php

namespace Gadya\Cms\Forms\Builder;

/**
 * What would stop a built form from working, in words the person building
 * it can act on. Checked before a form is saved and again before a form
 * written by the AI or copied from a template is kept, so a form that
 * reaches the site can always be filled in.
 */
class FormSchemaValidator
{
    public function __construct(private readonly FieldTypes $types) {}

    /**
     * @param  array<array-key, mixed>  $fields  As saved, or as the builder holds them
     * @return list<string>
     */
    public function errors(array $fields): array
    {
        $errors = [];
        $schema = new FormSchema($fields);
        $seen = [];
        $raw = FormSchema::normalise($fields);

        if ($schema->inputs() === []) {
            $errors[] = 'Add at least one question for people to answer.';
        }

        foreach ($raw as $position => $field) {
            $name = $field['label'] !== '' ? '"'.$field['label'].'"' : 'Field '.($position + 1);
            $type = $this->types->get((string) $field['type']);

            if ($type === null) {
                $errors[] = "{$name} is a kind of field this site does not know ({$field['type']}).";

                continue;
            }

            if ($type->isInput() && $field['type'] !== 'hidden' && $field['label'] === '') {
                $errors[] = 'Field '.($position + 1).' ('.$type->label.') needs a question.';
            }

            if ($type->hasChoices() && $field['options'] === []) {
                $errors[] = "{$name} needs at least one choice.";
            }

            if (($field['rules']['pattern'] ?? null) !== null && filled($field['rules']['pattern'])
                && @preg_match('/'.str_replace('/', '\/', (string) $field['rules']['pattern']).'/u', '') === false) {
                $errors[] = "{$name} has a \"must match\" pattern that is not a valid regular expression.";
            }

            foreach (['min', 'max'] as $bound) {
                if (isset($field['rules'][$bound]) && $field['rules'][$bound] !== '' && ! is_numeric($field['rules'][$bound])) {
                    $errors[] = "{$name}: the {$bound}imum must be a number.";
                }
            }

            if (is_numeric($field['rules']['min'] ?? null) && is_numeric($field['rules']['max'] ?? null) && (float) $field['rules']['min'] > (float) $field['rules']['max']) {
                $errors[] = "{$name}: the smallest allowed is more than the largest.";
            }

            foreach ((array) ($field['logic']['rules'] ?? []) as $rule) {
                if (! in_array($rule['field'], $seen, true)) {
                    $errors[] = "{$name} depends on a question that is not above it (\"{$rule['field']}\").";
                }
            }

            if ($type->isInput()) {
                $seen[] = $field['key'];
            }
        }

        $steps = $schema->steps();

        if (count($steps) > (int) config('gadya-cms.forms.builder.max_steps', 12)) {
            $errors[] = 'A form can have at most '.(int) config('gadya-cms.forms.builder.max_steps', 12).' steps.';
        }

        if (count($raw) > (int) config('gadya-cms.forms.builder.max_fields', 100)) {
            $errors[] = 'A form can have at most '.(int) config('gadya-cms.forms.builder.max_fields', 100).' fields.';
        }

        return array_values(array_unique($errors));
    }
}
