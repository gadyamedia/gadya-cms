<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Forms\Builder\FormRenderer;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Longer copy the client writes with a little structure - a bullet list, a
 * bold phrase, a link - rendered safely.
 *
 * The live editor edits plain text, so a legal page or a policy with lists
 * and links used to stay in the template, out of the client's reach. As
 * Markdown it can be hers: `**bold**`, `[a link](https://...)` and a line
 * starting with `- ` are all she needs, and anything that looks like HTML
 * is stripped rather than trusted.
 */
class SiteMarkdown
{
    private static bool $embedding = false;

    public function render(mixed $text): HtmlString
    {
        $text = trim(is_scalar($text) ? (string) $text : '');

        if ($text === '') {
            return new HtmlString('');
        }

        $html = Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return new HtmlString($this->embedForms($html));
    }

    /**
     * `[form:catering-order]` in the text becomes that form: on a line of
     * its own it replaces the paragraph, anywhere else it sits where it
     * was written. A form that is not published leaves nothing behind for
     * a visitor.
     */
    private function embedForms(string $html): string
    {
        /* Never a form inside a form's own text. */
        if (self::$embedding || ! str_contains($html, '[form:') || ! config('gadya-cms.forms.builder.enabled', true)) {
            return $html;
        }

        self::$embedding = true;

        try {
            return $this->replaceForms($html);
        } finally {
            self::$embedding = false;
        }
    }

    private function replaceForms(string $html): string
    {
        return (string) preg_replace_callback(
            '/<p>\s*\[form:([a-z0-9-]+)\]\s*<\/p>|\[form:([a-z0-9-]+)\]/',
            fn (array $match): string => app(FormRenderer::class)->render(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''))->toHtml(),
            $html,
        );
    }
}
