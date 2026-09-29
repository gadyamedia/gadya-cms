<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Support\Facades\App;

/**
 * Writes out the `@foreach` loops in a form's markup that draw its
 * choices - `<option>`s, radio buttons, tick boxes - one copy per item,
 * in each of the site's languages, so the scanner sees the choices the
 * visitor sees.
 *
 * The collection is worked out wherever it comes from: `__('file.key')`
 * in the lang files, `config('x')`, an array written in the template
 * (`@php $x = [...] @endphp`), a static call (`SiteContent::services()`),
 * or a variable the page's controller, route or view composer hands the
 * template. A loop whose collection cannot be worked out is left as one
 * copy, and reported with the fields it draws, for a person to fill in.
 */
class BladeLoops
{
    /** @var array<string, array{ok: bool, value: mixed}> */
    private array $variables = [];

    /** @var array<string, array{expression: string, uses: array<string, string>, file: string}>|null */
    private ?array $viewData = null;

    /** @var list<array{expression: string, fields: list<string>}> */
    private array $unresolved = [];

    private string $template = '';

    private ?string $path = null;

    /** @var list<string> */
    private array $directories = [];

    public function __construct(
        private readonly TemplateValues $values,
        private readonly ViewData $viewDataReader,
    ) {}

    /**
     * @param  list<string>  $locales  The languages to write the loops out in, the default first
     * @param  list<string>  $directories  Where the page's controllers and routes are
     * @return array{markup: array<string, string>, unresolved: list<array{expression: string, fields: list<string>}>, loops: int}
     */
    public function expand(string $markup, string $template, ?string $path, array $locales, array $directories): array
    {
        $loops = preg_match_all('/(?<!@)@(?:foreach|forelse)\b/', $markup);

        if ($loops === 0) {
            return ['markup' => [($locales[0] ?? App::getLocale()) => $markup], 'unresolved' => [], 'loops' => 0];
        }

        $this->template = $template;
        $this->path = $path;
        $this->directories = $directories;
        $this->viewData = null;
        $expanded = [];
        $unresolved = [];
        $original = App::getLocale();

        try {
            foreach ($locales as $index => $locale) {
                App::setLocale($locale);
                $this->variables = [];
                $this->unresolved = [];
                $expanded[$locale] = $this->expandIn($markup, []);

                if ($index === 0) {
                    $unresolved = $this->unresolved;
                }
            }
        } finally {
            App::setLocale($original);
        }

        return ['markup' => $expanded, 'unresolved' => $unresolved, 'loops' => $loops];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function expandIn(string $markup, array $scope): string
    {
        $out = '';
        $offset = 0;

        while (preg_match('/(?<!@)@(foreach|forelse)\s*\(/', $markup, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $open = $start + strlen($match[0][0]) - 1;
            $close = PhpSource::closing($markup, $open);
            $loop = $close === null ? null : $this->loopAt($markup, $close + 1, $match[1][0]);

            if ($close === null || $loop === null) {
                $out .= substr($markup, $offset, $open + 1 - $offset);
                $offset = $open + 1;

                continue;
            }

            $out .= substr($markup, $offset, $start - $offset);
            $header = substr($markup, $open + 1, $close - $open - 1);
            $body = $loop['body'];
            $offset = $loop['end'];

            if (preg_match('/<(option|input|select|textarea)\b/i', $body) !== 1 || preg_match('/^(.*)\s+as\s+(?:\$(\w+)\s*=>\s*)?\$(\w+)\s*$/s', $header, $parts) !== 1) {
                $out .= substr($markup, $start, $loop['end'] - $start);

                continue;
            }

            $collection = $this->evaluate(trim($parts[1]), $scope);
            $items = $collection['ok'] ? $this->iterable($collection['value']) : null;

            if ($items === null) {
                $this->unresolved[] = ['expression' => trim($parts[1]), 'fields' => $this->fieldsOf($body, $out)];
                $out .= $this->withoutState($body);

                continue;
            }

            foreach ($items as $key => $item) {
                $inner = [...$scope, $parts[3] => $item];

                if ($parts[2] !== '') {
                    $inner[$parts[2]] = $key;
                }

                $out .= $this->render($this->expandIn($body, $inner), $inner);
            }
        }

        return $out.substr($markup, $offset);
    }

    /**
     * A loop's body and where its `@endforeach` ends, from just after its
     * header.
     *
     * @return array{body: string, end: int}|null
     */
    private function loopAt(string $markup, int $from, string $kind): ?array
    {
        $depth = 1;
        $offset = $from;
        $empty = null;

        while (preg_match('/(?<!@)@(foreach|forelse|endforeach|endforelse|empty)\b/', $markup, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $word = $match[1][0];
            $offset = $match[0][1] + strlen($match[0][0]);

            if ($word === 'foreach' || $word === 'forelse') {
                $depth++;
            } elseif ($word === 'empty') {
                /* @empty($x) is a directive of its own; a bare @empty in a @forelse ends its body. */
                if ($depth === 1 && $kind === 'forelse' && preg_match('/\G\s*\(/', $markup, $ignored, 0, $offset) !== 1) {
                    $empty = $match[0][1];
                }
            } elseif (--$depth === 0) {
                return ['body' => substr($markup, $from, ($empty ?? $match[0][1]) - $from), 'end' => $offset];
            }
        }

        return null;
    }

    /**
     * A loop body with its echoes worked out for one item.
     *
     * @param  array<string, mixed>  $scope
     */
    private function render(string $body, array $scope): string
    {
        $body = $this->withoutState($body);

        return (string) preg_replace_callback('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}/s', function (array $echo) use ($scope): string {
            $raw = isset($echo[2]) && $echo[2] !== '';
            $expression = trim($raw ? $echo[2] : $echo[1]);

            /* Words from the lang files stay as keys, for the converter to read in every language. */
            if (LangFiles::keyIn($echo[0]) !== null) {
                return $echo[0];
            }

            $value = $this->values->evaluate($expression, $scope);

            if (! $value['ok'] || (! is_scalar($value['value']) && $value['value'] !== null && ! $value['value'] instanceof \Stringable)) {
                return $echo[0];
            }

            $text = is_bool($value['value']) ? ($value['value'] ? '1' : '') : (string) $value['value'];

            return $raw ? $text : e($text);
        }, $body);
    }

    /** Directives that only say which choice was picked last time. */
    private function withoutState(string $body): string
    {
        $out = '';
        $offset = 0;

        while (preg_match('/(?<!@)@(selected|checked|disabled|readonly)\s*\(/', $body, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = PhpSource::closing($body, $open);
            $out .= substr($body, $offset, $match[0][1] - $offset);
            $offset = $close === null ? strlen($body) : $close + 1;
        }

        return $out.substr($body, $offset);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{ok: bool, value: mixed}
     */
    private function evaluate(string $expression, array $scope): array
    {
        preg_match_all('/\$(\w+)/', $expression, $variables);
        $uses = PhpSource::uses($this->template);

        foreach (array_unique($variables[1]) as $name) {
            if (array_key_exists($name, $scope)) {
                continue;
            }

            $found = $this->variable($name);

            if (! $found['ok']) {
                return ['ok' => false, 'value' => null];
            }

            $scope[$name] = $found['value'];
        }

        return $this->values->evaluate($expression, $scope, $uses);
    }

    /**
     * A variable the template does not make in a loop: from `@php` in the
     * template, else from whatever renders it.
     *
     * @return array{ok: bool, value: mixed}
     */
    private function variable(string $name): array
    {
        if (isset($this->variables[$name])) {
            return $this->variables[$name];
        }

        $this->variables[$name] = ['ok' => false, 'value' => null];
        $source = null;
        $uses = PhpSource::uses($this->template);

        preg_match_all('/@php\b(.*?)@endphp|@php\s*(\(.*?\))\s*$/ms', $this->template, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            $code = ($block[1] ?? '') !== '' ? $block[1] : ($block[2] ?? '');
            $source = PhpSource::assignment($code, $name) ?? $source;
        }

        if ($source === null && $this->path !== null) {
            $view = $this->viewDataReader->viewName($this->path);
            $this->viewData ??= $view === null ? [] : $this->viewDataReader->for($view, $this->directories);

            if (isset($this->viewData[$name])) {
                $source = $this->viewData[$name]['expression'];
                $uses = $this->viewData[$name]['uses'];
            }
        }

        if ($source !== null) {
            $this->variables[$name] = $this->values->evaluate($source, [], $uses);
        }

        return $this->variables[$name];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function iterable(mixed $value): ?array
    {
        return match (true) {
            is_array($value) => $value,
            $value instanceof \Traversable => iterator_to_array($value),
            default => null,
        };
    }

    /**
     * The fields a loop draws: the controls inside it, or the dropdown it
     * sits in.
     *
     * @return list<string>
     */
    private function fieldsOf(string $body, string $before): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname\s*=\s*["\']([^"\'{]+)["\']/i', $body, $names);
        $fields = array_map(fn (string $name): string => (string) preg_replace('/\[\]$/', '', $name), $names[1]);

        if (preg_match_all('/<select\b[^>]*\bname\s*=\s*["\']([^"\'{]+)["\'][^>]*>/i', $before, $selects, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            $last = end($selects);

            if (! str_contains(substr($before, $last[0][1]), '</select>')) {
                $fields[] = (string) preg_replace('/\[\]$/', '', $last[1][0]);
            }
        }

        return array_values(array_unique($fields));
    }
}
