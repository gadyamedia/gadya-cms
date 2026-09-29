<?php

namespace Gadya\Cms\Forms\Builder;

/**
 * The words of a label, a legend or a choice in a template, and nothing
 * else: the control it wraps (and a dropdown's choices), error messages,
 * Blade directives and echoes that are not words are all left out, and
 * a "required" mark - `*`, `<span>*</span>`, `(required)`,
 * `<abbr title="required">*</abbr>` - is taken off and noted, so it is
 * never shown twice.
 *
 * What is left is a list of parts: plain text, and lang keys (`{{
 * __('contact.name') }}`) for the converter to read in every language.
 */
class LabelText
{
    /** A tag's insides, past `>` in quoted attribute values (`value="{{ $loc->slug }}"`). */
    public const ATTRIBUTES = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    /** A required mark at either end of a label, in words. */
    private const MARKER = '(?:\*|＊|\(\s*required\s*\)|\(\s*обязательно\s*\)|\(\s*обов[\'’]язково\s*\))';

    /**
     * @return array{parts: list<array{text?: string, key?: string}>, required: bool, links: list<string>}
     */
    public static function parse(string $blade): array
    {
        $blade = self::withoutMarkup($blade);
        $required = false;

        preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/is', $blade, $links);

        /* A required mark written as its own element. */
        $marked = (string) preg_replace('/<(span|abbr|sup|small|em|strong|b|i)\b[^>]*>\s*(?:\*|＊|\(?required\)?)\s*<\/\1>/iu', '', $blade);

        if ($marked !== $blade) {
            $required = true;
            $blade = $marked;
        }

        /* Each element's words a part of their own, so a link's words can be told from the sentence around it. */
        $blade = (string) preg_replace('/<'.self::ATTRIBUTES.'>/s', "\x1F", $blade);
        $parts = [];
        $offset = 0;

        preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}|@lang\s*\(/s', $blade, $echoes, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($echoes as $echo) {
            $start = $echo[0][1];

            if ($start < $offset) {
                continue;
            }

            self::addText($parts, substr($blade, $offset, $start - $offset));

            if (str_starts_with($echo[0][0], '@lang')) {
                $close = PhpSource::closing($blade, $start + strlen($echo[0][0]) - 1);
                $end = $close === null ? strlen($blade) : $close + 1;
                $key = LangFiles::keyIn(substr($blade, $start, $end - $start));
            } else {
                $end = $start + strlen($echo[0][0]);
                $key = LangFiles::keyIn($echo[0][0]);
            }

            if ($key !== null) {
                $parts[] = ['key' => $key];
            }

            $offset = $end;
        }

        self::addText($parts, substr($blade, $offset));

        /* A required mark at either end, as words. */
        if ($parts !== []) {
            $last = array_key_last($parts);

            if (isset($parts[$last]['text'])) {
                [$text, $found] = self::strip($parts[$last]['text']);
                $required = $required || $found;
                $text === '' ? array_pop($parts) : $parts[$last]['text'] = $text;
            }
        }

        if ($parts !== [] && isset($parts[0]['text'])) {
            [$text, $found] = self::strip($parts[0]['text']);
            $required = $required || $found;
            $text === '' ? array_shift($parts) : $parts[0]['text'] = $text;
        }

        return ['parts' => array_values($parts), 'required' => $required, 'links' => array_values(array_unique($links[2]))];
    }

    /**
     * Words with a required mark taken off either end, and whether one was.
     *
     * @return array{0: string, 1: bool}
     */
    public static function strip(string $text): array
    {
        $found = false;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        do {
            $before = $text;
            $text = (string) preg_replace('/<(span|abbr|sup|small|em|strong|b|i)\b[^>]*>\s*(?:\*|＊|\(?required\)?)\s*<\/\1>\s*$/iu', '', $text);
            $text = trim((string) preg_replace('/\s*'.self::MARKER.'\s*$/iu', '', $text));
            $text = trim((string) preg_replace('/^\s*'.self::MARKER.'\s*/iu', '', $text));
            $found = $found || $text !== $before;
        } while ($text !== $before && $text !== '');

        return [$text, $found];
    }

    /**
     * The parts as one line of plain text: the text parts only when there
     * are keys, or null when there is no plain text at all.
     *
     * @param  list<array{text?: string, key?: string}>  $parts
     */
    public static function plain(array $parts): ?string
    {
        $text = self::join(array_column($parts, 'text'));

        return $text === '' ? null : $text;
    }

    /**
     * Words joined as a sentence: one space between parts, none before
     * punctuation.
     *
     * @param  list<string>  $words
     */
    public static function join(array $words): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', implode(' ', $words)));

        return (string) preg_replace('/\s+([,.;:!?)])/u', '$1', $text);
    }

    /**
     * Leave out what is inside a label but is not its words: the controls
     * (and a dropdown's choices), Blade comments, `@error` blocks, other
     * directives, and echoes of variables.
     */
    public static function withoutMarkup(string $blade): string
    {
        $blade = (string) preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', ' ', $blade);
        $blade = (string) preg_replace('/<(select|textarea|button|script|style|template|svg)\b.*?<\/\1>/is', ' ', $blade);
        $blade = (string) preg_replace('/<(input|img)\b'.self::ATTRIBUTES.'>/is', ' ', $blade);

        return self::withoutDirectives($blade);
    }

    /**
     * Blade directives removed - `@error(...)` to `@enderror` whole, the
     * rest with their brackets matched past strings - leaving `@lang(...)`.
     */
    public static function withoutDirectives(string $blade): string
    {
        $blade = (string) preg_replace('/@error\b.*?@enderror/s', ' ', $blade);
        $out = '';
        $length = strlen($blade);

        for ($i = 0; $i < $length; $i++) {
            if ($blade[$i] === '@' && preg_match('/\G@(\w+)/', $blade, $match, 0, $i) === 1 && ($i === 0 || preg_match('/[\w@.]/', $blade[$i - 1]) !== 1)) {
                if ($match[1] === 'lang') {
                    $out .= '@';

                    continue;
                }

                $end = $i + strlen($match[0]);
                $open = $end;

                while ($open < $length && ($blade[$open] === ' ' || $blade[$open] === "\t")) {
                    $open++;
                }

                if ($open < $length && $blade[$open] === '(') {
                    $close = PhpSource::closing($blade, $open);
                    $end = $close === null ? $length : $close + 1;
                }

                $i = $end - 1;
                $out .= ' ';

                continue;
            }

            $out .= $blade[$i];
        }

        return $out;
    }

    /**
     * @param  list<array{text?: string, key?: string}>  $parts
     */
    private static function addText(array &$parts, string $text): void
    {
        foreach (explode("\x1F", $text) as $piece) {
            $piece = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($piece, ENT_QUOTES | ENT_HTML5)));

            if ($piece !== '') {
                $parts[] = ['text' => $piece];
            }
        }
    }
}
