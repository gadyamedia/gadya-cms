<?php

namespace Gadya\Cms\Content;

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
    public function render(mixed $text): HtmlString
    {
        $text = trim(is_scalar($text) ? (string) $text : '');

        if ($text === '') {
            return new HtmlString('');
        }

        return new HtmlString(Str::markdown($text, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]));
    }
}
