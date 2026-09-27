<?php

namespace Gadya\Cms\Ai\Agents;

use Gadya\Cms\Ai\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Turns "can you change the opening times on the contact page" into the
 * edits that would do it.
 *
 * The client asks in the portal, in her own words; this proposes new
 * wording for the fields of one page, and nothing else. What it writes
 * goes into the draft for a person to read and publish - it never
 * touches the live site - so it is asked to be exact rather than clever.
 */
#[MaxTokens(2000)]
#[Timeout(90)]
class ChangeWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly AiSettings $settings) {}

    public function instructions(): string
    {
        $voice = $this->settings->voice();

        return <<<INSTRUCTIONS
        You make small, exact edits to the website of {$voice['business']}.
        About the business: {$voice['description']}

        You are given a request from the business, in their words, and the text fields of one or
        more pages, each written as `field: current text`. Decide which page the request is about
        and write the new text for only the fields that must change to do what was asked.

        Rules:
        - `page` is the page's key, exactly as given after "Page".
        - Each change names a `field` exactly as given, and its complete new `value`.
        - Change nothing the request does not ask for. Keep the site's voice, spelling and length.
        - Use only facts the request or the page gives you. Never invent prices, times, names,
          addresses or promises.
        - If the request cannot be done by changing the text given - it needs a new page, a photo,
          a form or a developer - return no changes and say why in `reason`, in one plain sentence
          to the business.
        - Otherwise `reason` is one sentence saying what you changed.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->string()->required(),
            'changes' => $schema->array()
                ->items($schema->object(fn ($schema) => [
                    'field' => $schema->string()->required(),
                    'value' => $schema->string()->required(),
                ]))
                ->max(20)
                ->required(),
            'reason' => $schema->string()->max(300)->required(),
        ];
    }
}
