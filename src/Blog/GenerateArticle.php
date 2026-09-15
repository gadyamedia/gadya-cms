<?php

namespace Gadya\Cms\Blog;

use Gadya\Cms\Ai\Agents\ArticleWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\Prompter;
use Illuminate\Support\Str;

/**
 * Turns a topic into the fields of a post, through the AI service chosen
 * in the panel. Nothing here is saved: the caller decides whether the
 * result becomes a new draft or replaces the body of an existing one.
 */
class GenerateArticle
{
    public function __construct(
        private readonly Prompter $prompter,
        private readonly AiSettings $settings,
        private readonly InternalLinkSuggester $links,
        private readonly BlogRepository $blog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ArticleRequest $request, ?int $ignorePostId = null): array
    {
        $links = $this->links->suggest($request->topic, $request->keyword);

        $lines = ["Write an article on this topic: {$request->topic}"];

        if ($request->keyword !== '') {
            $lines[] = "Target keyword: {$request->keyword}. Use it in the title, meta title, meta description, and the opening paragraph.";
        }

        if ($request->location !== '') {
            $lines[] = "Target location: {$request->location}. Mention it naturally.";
        }

        $lines[] = "Search intent: {$request->intent}.";

        if ($links !== []) {
            $lines[] = 'Pages on this site to link to naturally (use at least two): '.json_encode($links, JSON_UNESCAPED_SLASHES);
        }

        $agent = app(ArticleWriter::class)->withLinks($links);
        $response = $this->prompter->prompt($agent, implode("\n", $lines));

        $slug = Str::slug((string) ($response['slug'] ?: $response['title']));

        return [
            'title' => (string) $response['title'],
            'slug' => $this->blog->uniqueSlug($slug, $ignorePostId),
            'meta_title' => (string) $response['meta_title'],
            'meta_description' => (string) $response['meta_description'],
            'excerpt' => (string) $response['excerpt'],
            'content' => (string) $response['body_html'],
            'hero_alt' => (string) $response['hero_alt'],
            'faq' => $response['faq'],
            'reading_time' => (string) $response['reading_time'],
            'ai_meta' => [
                'generated_at' => now()->toIso8601String(),
                'provider' => $this->settings->provider(),
                'model' => $this->settings->model(),
                'topic' => $request->topic,
                'search_intent' => $request->intent,
                'links_offered' => $links,
            ],
        ];
    }
}
