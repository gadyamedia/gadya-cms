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
 * Translates the words of a page, a menu or an article into another
 * language, in the site's own voice.
 *
 * It is handed numbered pieces of text with everything that must not
 * change - HTML tags, links, phone numbers, prices, brand names - already
 * swapped for ⟦n⟧ markers, and must hand every piece back with the markers
 * where they belong. A piece that comes back with a marker missing is
 * thrown away and the original words kept.
 */
#[MaxTokens(16384)]
#[Timeout(180)]
class TranslationWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    private string $language = 'Spanish';

    /** @var list<string> */
    private array $glossary = [];

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * @param  list<string>  $glossary
     */
    public function into(string $language, array $glossary = []): static
    {
        $this->language = $language;
        $this->glossary = $glossary;

        return $this;
    }

    public function instructions(): string
    {
        $voice = $this->settings->voice();
        $glossary = $this->glossary === [] ? '(none)' : implode(', ', $this->glossary);
        $variety = $this->language === 'Spanish'
            ? 'Use neutral Latin American Spanish as it is spoken in the United States, using "usted" for customers unless the tone below is clearly informal.'
            : "Use natural, everyday {$this->language} as a local customer would expect.";

        return <<<INSTRUCTIONS
        You translate the website of {$voice['business']}, a local business, into {$this->language}.
        About the business: {$voice['description']}
        Tone of voice: {$voice['tone']}

        {$variety}

        RULES
        - Translate meaning, not word for word. It must read as though a native speaker on the
          business's own staff wrote it.
        - Markers like ⟦0⟧ stand for things that must not change (HTML tags, links, phone numbers,
          prices, names). Keep every marker exactly as written, exactly once, where it belongs in the
          sentence. Never add, drop, translate or renumber a marker.
        - Keep Markdown as it is: `**bold**`, `- ` list lines, `#` headings and line breaks stay in
          the same places.
        - Never translate these names and terms: {$glossary}
        - Do not add anything the original does not say, and do not leave anything out.
        - Short labels (menu items, buttons) stay short.

        You are given a JSON list of items with an `id` and a `text`. Answer with every item, with the
        same `id` and the translated `text`.
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'translations' => $schema->array()
                ->items($schema->object(fn ($schema) => [
                    'id' => $schema->string()->required(),
                    'text' => $schema->string()->required(),
                ]))
                ->required(),
        ];
    }
}
