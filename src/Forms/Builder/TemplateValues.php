<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Stringable;
use Throwable;

/**
 * Works out the value of the few kinds of expression a form's template
 * draws its choices and addresses from - `__('contact.services')`,
 * `config('services.leads.email')`, `[...]` written out,
 * `SiteContent::services()`, `$service['name']` inside a loop - in the
 * running application, and in each of the site's languages.
 *
 * Only expressions that read are run: literals, arrays, variables the
 * caller hands over, a short list of functions (translation, config,
 * env, route and URL helpers, string helpers), class constants, and
 * static or method calls whose names do not write, send or delete. No
 * assignment, `new`, closure, backtick or variable variable is ever
 * run; anything else is reported as not worked out.
 */
class TemplateValues
{
    private const FUNCTIONS = [
        '__', 'trans', 'trans_choice', 'config', 'env', 'route', 'url', 'asset', 'secure_url', 'e',
        'ucfirst', 'ucwords', 'lcfirst', 'strtolower', 'strtoupper', 'mb_strtolower', 'mb_strtoupper', 'trim', 'ltrim', 'rtrim',
        'str_replace', 'implode', 'explode', 'array_keys', 'array_values', 'array_combine', 'array_merge', 'range', 'count',
        'sprintf', 'number_format', 'collect', 'data_get', 'str', 'array_column', 'json_decode', 'strval', 'intval', 'is_array',
    ];

    /** Method names that change something, send something or run something: never called. */
    private const WRITES = '/^(create\w*|forceCreate\w*|make\w*|insert\w*|update\w*|upsert|delete\w*|destroy|forceDelete\w*|save\w*|push\w*|pull|increment\w*|decrement\w*|truncate|drop\w*|send\w*|queue\w*|later|dispatch\w*|fire|flush\w*|forget\w*|put\w*|set\w*|store\w*|write\w*|move\w*|copy\w*|call\w*|exec\w*|shell\w*|system|passthru|process|run\w*|unlink|remove\w*|clear\w*|attach|detach|sync\w*|toggle|touch|notify\w*|broadcast\w*|event|login\w*|logout\w*|raw|statement|unprepared|affectingStatement|cursor|lock\w*|register\w*|extend|macro|mixin|swap|fake|spy|mock|partialMock|shouldReceive|instance|bind\w*|singleton\w*|alias|share|resolving|afterResolving|terminate|handle|__invoke|__construct|__destruct)$/i';

    /**
     * @param  array<string, mixed>  $scope  Variables the expression may read, by name without the `$`
     * @param  array<string, string>  $uses  Short class name => class, from the file the expression is in
     * @return array{ok: bool, value: mixed}
     */
    public function evaluate(string $expression, array $scope = [], array $uses = []): array
    {
        $code = $this->compile(trim($expression), array_keys($scope), $uses);

        if ($code === null) {
            return ['ok' => false, 'value' => null];
        }

        try {
            $value = (static function (string $__code, array $__scope): mixed {
                extract($__scope, EXTR_SKIP);

                return eval('return '.$__code.';');
            })($code, $scope);
        } catch (Throwable) {
            return ['ok' => false, 'value' => null];
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return ['ok' => true, 'value' => $value];
    }

    /**
     * The expression in each language, the app's own language put back
     * after.
     *
     * @param  list<string>  $locales
     * @param  array<string, mixed>|callable(string): array<string, mixed>  $scope
     * @param  array<string, string>  $uses
     * @return array<string, mixed>|null locale => value; null when it cannot be worked out
     */
    public function inLocales(string $expression, array $locales, array|callable $scope = [], array $uses = []): ?array
    {
        return $this->eachLocale($locales, function (string $locale) use ($expression, $scope, $uses): array {
            return $this->evaluate($expression, is_callable($scope) ? $scope($locale) : $scope, $uses);
        });
    }

    /**
     * Run something once in each language, the app's own language put back
     * after; null when any language fails.
     *
     * @param  list<string>  $locales
     * @param  callable(string): array{ok: bool, value: mixed}  $work
     * @return array<string, mixed>|null
     */
    public function eachLocale(array $locales, callable $work): ?array
    {
        $original = App::getLocale();
        $values = [];

        try {
            foreach ($locales as $locale) {
                App::setLocale($locale);
                $result = $work($locale);

                if (! $result['ok']) {
                    return null;
                }

                $values[$locale] = $result['value'];
            }
        } finally {
            App::setLocale($original);
        }

        return $values;
    }

    /**
     * The expression as PHP safe to run, or null when it does anything
     * this class does not run.
     *
     * @param  list<string>  $variables
     * @param  array<string, string>  $uses
     */
    private function compile(string $expression, array $variables, array $uses): ?string
    {
        if ($expression === '' || str_contains($expression, '?>')) {
            return null;
        }

        $tokens = token_get_all('<?php '.$expression.';');
        array_shift($tokens);
        array_pop($tokens);

        $allowedTypes = [
            T_WHITESPACE, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, T_DOUBLE_COLON,
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_ARRAY, T_COALESCE, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL,
            T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_BOOLEAN_AND, T_BOOLEAN_OR,
            T_LOGICAL_AND, T_LOGICAL_OR, T_CLASS, T_ELLIPSIS, T_MATCH, T_DEFAULT,
        ];
        $allowedCharacters = ['[', ']', '(', ')', ',', '.', '?', ':', '!', '+', '-', '*', '/', '%', '<', '>'];
        $code = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if (! in_array($token, $allowedCharacters, true)) {
                    return null;
                }

                $code .= $token;

                continue;
            }

            [$type, $text] = $token;
            $next = $this->nextToken($tokens, $i);
            $previous = $this->previousToken($tokens, $i);

            if ($type === T_VARIABLE) {
                $name = substr($text, 1);

                if ($name === 'this' || ! in_array($name, $variables, true)) {
                    return null;
                }

                $code .= $text;

                continue;
            }

            if (in_array($type, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                $afterArrow = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                $isCall = $next === '(';

                if ($afterArrow) {
                    if ($isCall && preg_match(self::WRITES, $text) === 1) {
                        return null;
                    }

                    $code .= $text;

                    continue;
                }

                if (is_array($next) && $next[0] === T_DOUBLE_COLON) {
                    $code .= '\\'.ltrim($this->className($text, $uses), '\\');

                    continue;
                }

                if ($isCall) {
                    $function = strtolower(ltrim($text, '\\'));

                    if (! in_array($function, self::FUNCTIONS, true) && preg_match('/^\w+_(route|url)$/', $function) !== 1) {
                        return null;
                    }

                    $code .= '\\'.ltrim($text, '\\');

                    continue;
                }

                if (in_array(strtolower($text), ['true', 'false', 'null'], true)) {
                    $code .= $text;

                    continue;
                }

                /* A named argument (`key: value`) or a match arm's label. */
                if ($next === ':') {
                    $code .= $text;

                    continue;
                }

                return null;
            }

            if (! in_array($type, $allowedTypes, true)) {
                return null;
            }

            /* A string a method could call as a function (`->map('system')`) or a static method (`'Foo::bar'`). */
            if ($type === T_CONSTANT_ENCAPSED_STRING && $this->callable(substr($text, 1, -1))) {
                return null;
            }

            $code .= $text;
        }

        return $code;
    }

    /** Whether a string names a function outside the pure ones this class runs, or a static method. */
    private function callable(string $text): bool
    {
        $text = strtolower(trim($text, ' \\'));

        if (preg_match('/^[\w\\\\]+::\w+$/', $text) === 1) {
            return true;
        }

        return preg_match('/^[\w\\\\]+$/', $text) === 1
            && function_exists($text)
            && ! in_array($text, self::FUNCTIONS, true)
            && preg_match('/^(key|current|next|reset|end|prev|count|min|max|abs|round|floor|ceil|date|time|array_\w+|str_\w+|mb_\w+|is_\w+|in_array|sort|ksort|asort|rsort|usort|list|range|ord|chr|md5|sha1|crc32|strlen|strrev|substr|ucfirst|lcfirst|ucwords|nl2br|htmlspecialchars|strip_tags|number_format|sprintf|implode|explode|json_encode|json_decode|intval|floatval|boolval|strval|trim|ltrim|rtrim|str|e|__|trans|config|env|route|url|asset|collect|data_get|head|last|value|now|today|optional|filled|blank|class_basename|preg_\w+|array|label|name|title|slug|state|city|phone|email|message|service|services|location|locations)$/', $text) !== 1;
    }

    /**
     * @param  array<string, string>  $uses
     */
    private function className(string $name, array $uses): string
    {
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
            return '\\__GadyaCmsNoSuchClass';
        }

        $first = explode('\\', ltrim($name, '\\'))[0];

        if (! str_starts_with($name, '\\') && isset($uses[$first])) {
            return $uses[$first].substr(ltrim($name, '\\'), strlen($first));
        }

        return ltrim($name, '\\');
    }

    /**
     * @param  list<mixed>  $tokens
     */
    private function nextToken(array $tokens, int $index): mixed
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_WHITESPACE) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $tokens
     */
    private function previousToken(array $tokens, int $index): mixed
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_WHITESPACE) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
