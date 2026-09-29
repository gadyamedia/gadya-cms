<?php

namespace Gadya\Cms\Forms\Builder;

/**
 * Small readers for PHP and Blade source, shared by the form scanner and
 * the controller reader: brackets matched past strings, a call's
 * arguments, `'key' => value` pairs, a file's `use` statements, a
 * method's body. Source is only ever read, never run.
 */
class PhpSource
{
    /**
     * The offset of the bracket closing the one opened at `$open`, past any
     * strings inside; null when it never closes.
     */
    public static function closing(string $source, int $open): ?int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $stack = [];
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '\'' || $character === '"') {
                $i = self::skipString($source, $i);

                continue;
            }

            if (isset($pairs[$character])) {
                $stack[] = $pairs[$character];
            } elseif (in_array($character, $pairs, true)) {
                if (array_pop($stack) !== $character) {
                    return null;
                }

                if ($stack === []) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The arguments of a call, from the offset of its opening bracket.
     *
     * @return list<string>
     */
    public static function arguments(string $source, int $open): array
    {
        $depth = 0;
        $current = '';
        $arguments = [];
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '\'' || $character === '"') {
                $end = self::skipString($source, $i);
                $current .= substr($source, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if (in_array($character, ['(', '[', '{'], true)) {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif (in_array($character, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    $arguments[] = trim($current);

                    break;
                }
            } elseif ($character === ',' && $depth === 1) {
                $arguments[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        return array_values(array_filter($arguments, fn (string $argument): bool => $argument !== ''));
    }

    /**
     * `['name' => $request->name, ...]` as key => expression; null when the
     * argument is not an array written out.
     *
     * @return array<string, string>|null
     */
    public static function pairs(string $argument): ?array
    {
        $argument = trim($argument);

        if (! str_starts_with($argument, '[')) {
            return null;
        }

        $pairs = [];

        foreach (self::arguments($argument, 0) as $item) {
            if (preg_match('/^([\'"])([\w.-]+)\1\s*=>\s*(.+)$/s', trim($item), $match) === 1) {
                $pairs[$match[2]] = trim($match[3]);
            }
        }

        return $pairs;
    }

    public static function skipString(string $source, int $start): int
    {
        $quote = $source[$start];
        $length = strlen($source);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                $i++;

                continue;
            }

            if ($source[$i] === $quote) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * The expression that ends at the first `;`, or at a bracket closing
     * one it never opened, from `$start`.
     */
    public static function expression(string $source, int $start): string
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $character = $source[$i];

            if ($character === '\'' || $character === '"') {
                $i = self::skipString($source, $i);

                continue;
            }

            if (in_array($character, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($character, [')', ']', '}'], true)) {
                if ($depth-- === 0) {
                    break;
                }
            } elseif (($character === ';' || $character === ',') && $depth === 0) {
                break;
            }
        }

        return trim(substr($source, $start, $i - $start));
    }

    /**
     * @return array<string, string> short name => class
     */
    public static function uses(string $source): array
    {
        $uses = [];

        preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $uses[$match[2] ?? class_basename($match[1])] = $match[1];
        }

        /* Blade's own: @use('App\Data\Services') and @use('App\Data\Services', 'Data'). */
        preg_match_all('/@use\(\s*[\'"]([\w\\\\]+)[\'"](?:\s*,\s*[\'"](\w+)[\'"])?\s*\)/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $uses[$match[2] ?? class_basename($match[1])] = ltrim($match[1], '\\');
        }

        return $uses;
    }

    public static function namespaceOf(string $source): string
    {
        return preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $match) === 1 ? $match[1] : '';
    }

    /**
     * A method's body and the line it starts on.
     *
     * @return array{0: string|null, 1: int}
     */
    public static function method(string $source, string $method): array
    {
        if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [null, 0];
        }

        $start = $match[0][1];
        $parameters = self::closing($source, $start + strlen($match[0][0]) - 1);
        $open = $parameters === null ? false : strpos($source, '{', $parameters);

        if ($open === false) {
            return [null, 0];
        }

        $close = self::closing($source, $open);

        return $close === null
            ? [null, 0]
            : [substr($source, $open + 1, $close - $open - 1), substr_count(substr($source, 0, $start), "\n") + 1];
    }

    /**
     * The body of the innermost function or closure around an offset.
     */
    public static function enclosingFunction(string $source, int $offset): string
    {
        preg_match_all('/\bfunction\b[^{;]*\{/', $source, $matches, PREG_OFFSET_CAPTURE);
        $best = null;

        foreach ($matches[0] as [$text, $start]) {
            $open = $start + strlen($text) - 1;
            $close = self::closing($source, $open);

            if ($open < $offset && $close !== null && $close > $offset) {
                $best = [$open, $close];
            }
        }

        return $best === null ? $source : substr($source, $best[0] + 1, $best[1] - $best[0] - 1);
    }

    /**
     * The value given to `$name` last before an offset - `$services = ...;`
     * or `if ($recipient = ...)` - as written.
     */
    public static function assignment(string $source, string $name, ?int $before = null): ?string
    {
        $scope = $before === null ? $source : substr($source, 0, $before);

        if (preg_match_all('/\$'.preg_quote($name, '/').'\s*=(?![=>])/', $scope, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        [$text, $offset] = end($matches[0]);
        $expression = self::expression($source, $offset + strlen($text));

        return $expression === '' ? null : $expression;
    }
}
