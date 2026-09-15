<?php

namespace Gadya\Cms\Blog;

use Gadya\Cms\Models\Post;
use Illuminate\Support\Str;

/**
 * A 100-point check of how well an article is set up to be found: the
 * keyword where search engines look for it, headings a machine can
 * follow, enough words to be worth ranking, links in and out, and a FAQ
 * an answer engine can lift. Run on every save, so the score moves as
 * the client edits.
 */
class ContentAudit
{
    /**
     * @return array{score: int, passed: int, total: int, checks: list<array{label: string, points: int, passed: bool, recommendation: string}>}
     */
    public function audit(Post $post): array
    {
        $keyword = Str::lower(trim((string) $post->target_keyword));
        $body = (string) $post->content;
        $text = trim(strip_tags($body));
        $bodyText = Str::lower($text);
        $wordCount = str_word_count($text);
        $metaTitle = (string) $post->meta_title;
        $metaDescription = (string) $post->meta_description;
        $faqCount = is_array($post->faq) ? count($post->faq) : 0;
        $h2Count = preg_match_all('/<h2[\s>]/i', $body);
        $internalLinks = preg_match_all('/<a\s[^>]*href="(?!https?:\/\/|mailto:|tel:|#)[^"]+"/i', $body);
        $externalLinks = preg_match_all('/<a\s[^>]*href="https?:\/\/[^"]+"/i', $body);
        $opening = Str::lower(implode(' ', array_slice(preg_split('/\s+/', $text) ?: [], 0, 100)));

        $hasKeyword = fn (string $haystack): bool => $keyword !== '' && str_contains(Str::lower($haystack), $keyword);

        $hasH1 = (bool) preg_match('/<h1[\s>]/i', $body);
        preg_match_all('/<h([1-6])[\s>]/i', $body, $levelMatches);
        $skipsLevel = false;
        $previous = 1;

        foreach (array_map('intval', $levelMatches[1]) as $level) {
            if ($level > $previous + 1) {
                $skipsLevel = true;

                break;
            }

            $previous = $level;
        }

        $checks = [
            ['label' => 'Keyword in title', 'points' => 8, 'keyword' => true, 'passed' => $hasKeyword($post->title), 'recommendation' => 'Put the target keyword in the article title.'],
            ['label' => 'Keyword in meta title', 'points' => 5, 'keyword' => true, 'passed' => $hasKeyword($metaTitle), 'recommendation' => 'Put the target keyword in the meta title.'],
            ['label' => 'Keyword in meta description', 'points' => 5, 'keyword' => true, 'passed' => $hasKeyword($metaDescription), 'recommendation' => 'Put the target keyword in the meta description.'],
            ['label' => 'Keyword in the body', 'points' => 4, 'keyword' => true, 'passed' => $keyword !== '' && str_contains($bodyText, $keyword), 'recommendation' => 'Use the target keyword naturally in the article.'],
            ['label' => 'Keyword in the first 100 words', 'points' => 8, 'keyword' => true, 'passed' => $keyword !== '' && str_contains($opening, $keyword), 'recommendation' => 'Answer first: work the keyword into the opening paragraph so it can be quoted.'],
            ['label' => 'Meta title 30-60 characters', 'points' => 6, 'passed' => strlen($metaTitle) >= 30 && strlen($metaTitle) <= 60, 'recommendation' => 'Keep the meta title between 30 and 60 characters.'],
            ['label' => 'Meta description 140-160 characters', 'points' => 6, 'passed' => strlen($metaDescription) >= 140 && strlen($metaDescription) <= 160, 'recommendation' => 'Keep the meta description between 140 and 160 characters.'],
            ['label' => 'At least 900 words', 'points' => 10, 'passed' => $wordCount >= 900, 'recommendation' => "Expand the article to at least 900 words (currently {$wordCount})."],
            ['label' => 'At least 3 sections', 'points' => 8, 'passed' => $h2Count >= 3, 'recommendation' => 'Structure the article with at least three H2 headings.'],
            ['label' => 'Headings in order', 'points' => 6, 'passed' => ! $hasH1 && ! $skipsLevel, 'recommendation' => $hasH1 ? 'Remove the H1 from the body: the title is already the H1.' : 'Do not skip heading levels (H2 then H3, never H2 then H4).'],
            ['label' => 'At least 2 links to your own pages', 'points' => 8, 'passed' => $internalLinks >= 2, 'recommendation' => 'Link to at least two pages on this site.'],
            ['label' => 'Cites an outside source', 'points' => 4, 'passed' => $externalLinks >= 1, 'recommendation' => 'Link out to one authoritative source where you state a general fact.'],
            ['label' => 'At least 3 FAQ entries', 'points' => 6, 'passed' => $faqCount >= 3, 'recommendation' => 'Add at least three question and answer pairs.'],
            ['label' => 'Has an excerpt', 'points' => 4, 'passed' => trim((string) $post->excerpt) !== '', 'recommendation' => 'Write a short excerpt for the article list and link previews.'],
            ['label' => 'Has a photo with alt text', 'points' => 6, 'passed' => filled($post->image) && trim((string) $post->hero_alt) !== '', 'recommendation' => 'Choose a photo and describe it, for image search and screen readers.'],
            ['label' => 'Has a reading time', 'points' => 2, 'passed' => trim((string) $post->reading_time) !== '', 'recommendation' => 'Add a reading time, like "6 min read".'],
            ['label' => 'Slug includes the keyword', 'points' => 4, 'keyword' => true, 'passed' => $keyword !== '' && str_contains((string) $post->slug, Str::slug($keyword)), 'recommendation' => 'Put the keyword in the address.'],
        ];

        /*
         * Without a target keyword the keyword checks cannot be judged, so
         * they are left out of the total rather than counted as failures
         * the client can do nothing about.
         */
        $checks = array_values(array_filter($checks, fn (array $check): bool => $keyword !== '' || ! ($check['keyword'] ?? false)));

        $total = array_sum(array_column($checks, 'points'));
        $earned = array_sum(array_map(fn (array $check): int => $check['passed'] ? $check['points'] : 0, $checks));

        return [
            'score' => $total > 0 ? (int) round($earned / $total * 100) : 0,
            'passed' => count(array_filter($checks, fn (array $check): bool => $check['passed'])),
            'total' => count($checks),
            'checks' => array_map(fn (array $check): array => [
                'label' => $check['label'],
                'points' => $check['points'],
                'passed' => $check['passed'],
                'recommendation' => $check['recommendation'],
            ], $checks),
        ];
    }

    /**
     * Store the result on the post itself, without touching timestamps
     * or firing events: it is a note about the post, not an edit to it.
     */
    public function record(Post $post): void
    {
        $post->updateQuietly([
            'ai_meta' => [
                ...($post->ai_meta ?? []),
                'audit' => $this->audit($post),
                'audited_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
