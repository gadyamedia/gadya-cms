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
 * Writes a page's search snippet from the page's own words - the "Write
 * with AI" button beside every SEO section in the panel.
 */
#[MaxTokens(600)]
#[Timeout(45)]
class MetaWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly AiSettings $settings) {}

    public function instructions(): string
    {
        $voice = $this->settings->voice();

        return <<<INSTRUCTIONS
        You write SEO metadata for the website of {$voice['business']}.
        About the business: {$voice['description']}
        Service area: {$voice['area']}

        Given a page's content, produce:
        - meta_title: 50-60 characters, the page's primary subject near the front. Append
          " | {$voice['business']}" only when it fits.
        - meta_description: 150-160 characters, plain and factual, naming the place or audience
          when the content does, with a reason to click. No exclamation marks, no "Discover".

        Write only from the content you are given. Never invent services, prices or coverage
        the page does not state.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'meta_title' => $schema->string()->max(70)->required(),
            'meta_description' => $schema->string()->max(170)->required(),
        ];
    }
}
