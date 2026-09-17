<?php

namespace Gadya\Cms\Seo;

use Gadya\Cms\Models\Post;
use Illuminate\Http\Request;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * A page or an article as plain Markdown, for a reader that asked for
 * one - an AI assistant, a scraper that means well - rather than making
 * it pull the words out of the HTML.
 */
class Markdown
{
    /**
     * @param  array<string, mixed>  $page
     */
    public function forPage(array $page, string $url): string
    {
        $lines = ['# '.($page['heading'] ?? $page['title'] ?? ''), '', "Source: {$url}"];

        if (filled($page['description'] ?? null)) {
            $lines[] = '';
            $lines[] = trim((string) $page['description']);
        }

        foreach ($page['sections'] ?? [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            if (filled($section['title'] ?? null)) {
                $lines[] = '';
                $lines[] = '## '.$section['title'];
            }

            if (filled($section['subtitle'] ?? null)) {
                $lines[] = '';
                $lines[] = '*'.$section['subtitle'].'*';
            }

            if (filled($section['text'] ?? null)) {
                $lines[] = '';
                $lines[] = trim((string) $section['text']);
            }

            foreach ($section['items'] ?? [] as $item) {
                if (is_array($item) && (filled($item['title'] ?? null) || filled($item['text'] ?? null))) {
                    $lines[] = '';
                    $lines[] = '- **'.($item['title'] ?? '').'**'.(filled($item['text'] ?? null) ? ' — '.trim((string) $item['text']) : '');
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function forPost(Post $post, string $url): string
    {
        $converter = new HtmlConverter(['strip_tags' => true, 'header_style' => 'atx', 'remove_nodes' => 'script style']);

        $lines = ['# '.$post->title, '', "Source: {$url}"];

        if ($post->published_at !== null) {
            $lines[] = 'Published: '.$post->published_at->toDateString();
        }

        if (filled($post->excerpt)) {
            $lines[] = '';
            $lines[] = '> '.trim((string) $post->excerpt);
        }

        $lines[] = '';
        $lines[] = trim($converter->convert((string) $post->content));

        if (is_array($post->faq) && $post->faq !== []) {
            $lines[] = '';
            $lines[] = '## Questions people ask';

            foreach ($post->faq as $item) {
                $lines[] = '';
                $lines[] = '**'.($item['question'] ?? '').'**';
                $lines[] = '';
                $lines[] = (string) ($item['answer'] ?? '');
            }
        }

        return implode("\n", $lines)."\n";
    }

    public static function wanted(Request $request): bool
    {
        if (! config('gadya-cms.seo.markdown', true) || ! $request->isMethod('GET')) {
            return false;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));

        return str_contains($accept, 'text/markdown') && ! str_contains(explode(',', $accept)[0], 'text/html');
    }
}
