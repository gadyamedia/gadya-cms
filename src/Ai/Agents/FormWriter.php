<?php

namespace Gadya\Cms\Ai\Agents;

use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Forms\Builder\FieldTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns "a catering order form with dates, headcount and dietary needs"
 * into the questions of a draft form.
 *
 * What it writes is a starting point the client reads and changes before
 * publishing - never a live form - so it is asked for a short, plain form
 * in the business's voice, using only the kinds of field the site has.
 */
#[MaxTokens(3000)]
#[Timeout(90)]
class FormWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly FieldTypes $types,
    ) {}

    public function instructions(): string
    {
        $voice = $this->settings->voice();
        $types = collect($this->types->all())
            ->map(fn ($type, string $key): string => "{$key} ({$type->label})")
            ->implode(', ');

        return <<<INSTRUCTIONS
        You design short, friendly web forms for {$voice['business']}, a local business.
        About the business: {$voice['description']}

        You are given a description of the form they want. Write the form.

        Rules:
        - `title` is a short name for the form, e.g. "Catering order".
        - Each field has a `type` from this list, and nothing else: {$types}.
        - `label` is the question, in plain words, as the business would ask it. Keep it short.
        - Ask only what the business needs. Most forms need eight questions or fewer.
        - Choice fields (select, multi_select, radio, checkboxes) must have `options`: a list of short answers.
        - Use `name` for a person's name, `email` for an email address and `phone` for a phone number.
        - Use `page_break` (its label is the next step's title) only for a long form, to split it into two or three steps.
        - `required` is true only for what the business truly cannot do without.
        - `help` is optional: one short line under the question, only when it helps.
        - `success` is the thank-you message shown after sending, one or two sentences.
        - Write in American English. Never invent prices, times, addresses or promises.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(80)->required(),
            'fields' => $schema->array()
                ->items($schema->object(fn ($schema) => [
                    'type' => $schema->string()->required(),
                    'label' => $schema->string()->required(),
                    'required' => $schema->boolean()->required(),
                    'help' => $schema->string(),
                    'options' => $schema->array()->items($schema->string()),
                ]))
                ->max(30)
                ->required(),
            'success' => $schema->string()->max(300)->required(),
        ];
    }
}
