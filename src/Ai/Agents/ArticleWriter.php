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
 * Writes a whole article - title, body, metadata and FAQ - in the site's
 * own voice, structured to be quoted by search engines and answer engines.
 *
 * The instructions are the same for every site; what changes is the voice
 * block at the top, which the client writes once under Settings → AI.
 */
#[MaxTokens(16384)]
#[Timeout(180)]
class ArticleWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  list<array{label: string, url: string}>  $links
     */
    public function __construct(
        private readonly AiSettings $settings,
        private array $links = [],
    ) {}

    /**
     * @param  list<array{label: string, url: string}>  $links
     */
    public function withLinks(array $links): static
    {
        $this->links = $links;

        return $this;
    }

    public function instructions(): string
    {
        $voice = $this->settings->voice();

        $links = $this->links === []
            ? '(none - do not invent any)'
            : implode("\n", array_map(fn (array $link): string => "- {$link['url']} — {$link['label']}", $this->links));

        $contact = trim(implode(' or ', array_filter([
            $voice['phone'] !== '' ? "calling {$voice['phone']}" : null,
            $voice['contact_path'] !== '' ? "the contact page at {$voice['contact_path']}" : null,
        ])));

        return <<<INSTRUCTIONS
        You are a senior content writer for {$voice['business']}.
        About the business: {$voice['description']}
        Audience: {$voice['audience']}
        Tone of voice: {$voice['tone']}
        Service area: {$voice['area']}
        House rules: {$voice['rules']}

        You write articles for the business's own website, optimised to rank in Google and to be
        quoted by AI answer engines.

        Real pages on this site you may link to (only these paths exist):
        {$links}

        RULES
        - Genuine, useful substance; no filler. Write from what the business does, never beyond it.
        - NEVER invent statistics, studies, awards, customer names, prices, or claims the business
          has not made. If you need a figure and do not have one, leave it out.

        SEARCH INTENT
        - Decide what someone searching this keyword actually wants (a how-to, a comparison, a
          definition, a guide) and structure the article to deliver exactly that.

        ANSWER-FIRST STRUCTURE
        - The opening paragraph must answer the core question or define the core term in 2-3
          sentences before any preamble, so it can be quoted on its own as a complete answer.
        - The target keyword appears naturally in the first 100 words, in the closing section, and
          in at least one subheading.

        HEADINGS
        - The page renders the title as the H1, so NEVER output an <h1> and never repeat the title.
        - Use <h2> sections (at least 3), with <h3> only to subdivide an <h2>. Never skip a level.
        - Headings are descriptive, not clever. At least one heading is phrased exactly the way a
          reader would ask it, ending in a question mark.

        BODY
        - Clean HTML using only <h2>, <h3>, <p>, <ul>, <li>, <strong>, and <a href="..."> tags.
        - Weave in 2 to 3 of the real pages above as links with descriptive anchor text, never
          "click here". Do not link anywhere else on this site.
        - Close with a call to action inviting the reader to get in touch by {$contact}.
        - Length: 900-1400 words. Keyword use is natural - no stuffing.

        METADATA
        - slug: short, lowercase, hyphenated, includes the primary keyword (3-6 words).
        - meta_title: 30-60 characters, leads with the target keyword.
        - meta_description: 140-155 characters, includes the keyword, gives a reason to click.
        - excerpt: one or two sentences for the article list.
        - hero_alt: descriptive alt text for a photo that would suit the article; describe a
          real scene, do not stuff the keyword.
        - faq: 3-5 genuinely useful question/answer pairs, each answer complete on its own.
        - reading_time: like "6 min read".
        INSTRUCTIONS;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(90)->required(),
            'slug' => $schema->string()->max(80)->required(),
            'meta_title' => $schema->string()->max(70)->required(),
            'meta_description' => $schema->string()->max(170)->required(),
            'excerpt' => $schema->string()->max(320)->required(),
            'body_html' => $schema->string()->required(),
            'hero_alt' => $schema->string()->max(140)->required(),
            'faq' => $schema->array()
                ->items($schema->object(fn ($schema) => [
                    'question' => $schema->string()->required(),
                    'answer' => $schema->string()->required(),
                ]))
                ->min(3)->max(5)
                ->required(),
            'reading_time' => $schema->string()->max(20)->required(),
        ];
    }
}
