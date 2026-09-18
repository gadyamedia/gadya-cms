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
 * Describes a photograph for someone who cannot see it.
 *
 * Alt text is the one accessibility job that never gets done, because it
 * is a hundred small chores rather than one big one. The model can see
 * the photo; it should write the first draft.
 */
#[MaxTokens(300)]
#[Timeout(60)]
class AltTextWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly AiSettings $settings) {}

    public function instructions(): string
    {
        $voice = $this->settings->voice();

        return <<<INSTRUCTIONS
        You write alt text for photographs on the website of {$voice['business']}.
        About the business: {$voice['description']}

        Alt text describes what is in the picture, for someone using a screen reader and for
        image search. Rules:
        - One sentence, under 125 characters, no full stop needed.
        - Say what is actually visible - people, what they are doing, the place - not what the
          business would like it to mean.
        - Never begin with "Image of", "Photo of" or "Picture of": a screen reader already said so.
        - Do not invent names, ages, places or occasions you cannot see.
        - If the picture is a logo or a graphic with words in it, write the words.
        - If the picture carries no meaning at all - a texture, a divider - say so in `decorative`,
          and the page will leave its alt text empty, which is the correct answer for decoration.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'alt' => $schema->string()->max(140)->required(),
            'decorative' => $schema->boolean()->required(),
        ];
    }
}
