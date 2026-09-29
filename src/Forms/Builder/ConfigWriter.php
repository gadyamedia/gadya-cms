<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;

/**
 * Adds a form destination to the site's `config/gadya-cms.php`, for
 * `forms:convert --write-config` - and only then: a converter that edited
 * config unasked would be a converter nobody could run to see what it
 * would do.
 *
 * The file is read as PHP tokens, so comments and strings with brackets
 * in them are never mistaken for the arrays they describe. The entry
 * goes into `forms.builder.destinations`, creating whichever of those
 * keys the file does not have yet (the package's defaults fill in the
 * rest, as config is merged all the way down). Nothing already there is
 * changed: a destination of the same name is left alone.
 */
class ConfigWriter
{
    public function __construct(private readonly Filesystem $files) {}

    public function path(): string
    {
        return config_path('gadya-cms.php');
    }

    /**
     * The file as it would be with the destination added, and whether it
     * changes at all.
     *
     * @param  array<string, mixed>  $destination
     * @return array{path: string, before: string, after: string, changed: bool, exists: bool}
     */
    public function plan(string $key, array $destination): array
    {
        $path = $this->path();
        $exists = $this->files->exists($path);
        $before = $exists ? (string) $this->files->get($path) : '';
        $after = $exists ? $this->insert($before, $key, $destination) : $this->fresh($key, $destination);

        return ['path' => $path, 'before' => $before, 'after' => $after, 'changed' => $after !== $before, 'exists' => $exists];
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    public function write(string $key, array $destination): bool
    {
        $plan = $this->plan($key, $destination);

        if ($plan['changed']) {
            $this->files->put($plan['path'], $plan['after']);
        }

        return $plan['changed'];
    }

    /**
     * The lines that change, as a short unified diff.
     */
    public static function diff(string $before, string $after, string $name = 'config/gadya-cms.php'): string
    {
        $old = $before === '' ? [] : explode("\n", $before);
        $new = explode("\n", $after);
        $start = 0;

        while ($start < count($old) && $start < count($new) && $old[$start] === $new[$start]) {
            $start++;
        }

        $endOld = count($old) - 1;
        $endNew = count($new) - 1;

        while ($endOld >= $start && $endNew >= $start && $old[$endOld] === $new[$endNew]) {
            $endOld--;
            $endNew--;
        }

        $context = 2;
        $from = max(0, $start - $context);
        $lines = ['--- '.$name, '+++ '.$name, '@@ -'.($from + 1).' +'.($from + 1).' @@'];

        for ($i = $from; $i < $start; $i++) {
            $lines[] = ' '.$old[$i];
        }

        for ($i = $start; $i <= $endOld; $i++) {
            $lines[] = '-'.$old[$i];
        }

        for ($i = $start; $i <= $endNew; $i++) {
            $lines[] = '+'.$new[$i];
        }

        for ($i = $endOld + 1; $i <= min(count($old) - 1, $endOld + $context); $i++) {
            $lines[] = ' '.$old[$i];
        }

        return implode("\n", $lines);
    }

    /**
     * A value as it would be written in a config file: short arrays, one
     * entry a line, and a model's class as `::class`.
     */
    public static function export(mixed $value, int $depth = 0): string
    {
        $pad = str_repeat('    ', $depth + 1);
        $close = str_repeat('    ', $depth);

        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $lines = [];

            foreach ($value as $key => $item) {
                $lines[] = $pad.(array_is_list($value) ? '' : self::export((string) $key).' => ').($key === 'model' && is_string($item) ? self::className($item) : self::export($item, $depth + 1)).',';
            }

            return "[\n".implode("\n", $lines)."\n".$close.']';
        }

        return match (true) {
            is_string($value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_int($value), is_float($value) => (string) $value,
            default => throw new InvalidArgumentException('Only plain values can be written to config.'),
        };
    }

    private static function className(string $class): string
    {
        return preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $class) === 1 ? '\\'.ltrim($class, '\\').'::class' : self::export($class);
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function fresh(string $key, array $destination): string
    {
        return "<?php\n\nreturn ".self::export(['forms' => ['builder' => ['destinations' => [$key => $destination]]]]).";\n";
    }

    /**
     * @param  array<string, mixed>  $destination
     */
    private function insert(string $source, string $key, array $destination): string
    {
        $tokens = $this->tokens($source);
        $root = $this->rootArray($tokens);

        if ($root === null) {
            throw new InvalidArgumentException('config/gadya-cms.php does not return an array this command can add to; add the destination by hand.');
        }

        $path = ['forms', 'builder', 'destinations', $key];
        $array = $root;

        foreach ($path as $depth => $segment) {
            $entry = $this->entry($tokens, $array, $segment);

            if ($entry === null) {
                $value = [$key => $destination];

                foreach (array_reverse(array_slice($path, $depth + 1, count($path) - $depth - 2)) as $outer) {
                    $value = [$outer => $value];
                }

                return $this->append($source, $tokens, $array, $segment === $key ? $key : $segment, $segment === $key ? $destination : $value);
            }

            if ($segment === $key) {
                return $source;
            }

            $array = $entry;
        }

        return $source;
    }

    /**
     * Add `'key' => value,` as the last entry of the array opened at the
     * token given.
     *
     * @param  list<array{0: string, 1: int, 2: int|null}>  $tokens
     * @param  array{open: int, close: int}  $array
     */
    private function append(string $source, array $tokens, array $array, string $key, mixed $value): string
    {
        $openOffset = $tokens[$array['open']][1];
        $closeOffset = $tokens[$array['close']][1];
        $lineStart = strrpos(substr($source, 0, $openOffset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($source, $lineStart), $indent);
        $depth = intdiv(strlen(str_replace("\t", '    ', $indent[0])), 4);
        $entry = str_repeat('    ', $depth + 1).self::export($key).' => '.self::export($value, $depth + 1).',';

        $inner = trim(substr($source, $openOffset + 1, $closeOffset - $openOffset - 1));

        if ($inner === '') {
            return substr($source, 0, $openOffset + 1)."\n".$entry."\n".str_repeat('    ', $depth).substr($source, $closeOffset);
        }

        /* The last real token before the closing bracket, to add a comma after when it lacks one. */
        $last = $array['close'] - 1;

        while ($last > $array['open'] && in_array($tokens[$last][2], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $last--;
        }

        $comma = $tokens[$last][0] === ',' ? '' : ',';
        $insertAt = $tokens[$last][1] + strlen($tokens[$last][0]);

        return substr($source, 0, $insertAt).$comma."\n\n".$entry.substr($source, $insertAt);
    }

    /**
     * The `'key' => [ ... ]` entry of an array, as the tokens of its own
     * brackets.
     *
     * @param  list<array{0: string, 1: int, 2: int|null}>  $tokens
     * @param  array{open: int, close: int}  $array
     * @return array{open: int, close: int}|null
     */
    private function entry(array $tokens, array $array, string $key): ?array
    {
        $depth = 0;

        for ($i = $array['open'] + 1; $i < $array['close']; $i++) {
            [$text, , $type] = $tokens[$i];

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                $depth--;
            }

            if ($depth !== 0 || $type !== T_CONSTANT_ENCAPSED_STRING || trim($text, '\'"') !== $key) {
                continue;
            }

            $next = $this->next($tokens, $i);

            if ($next === null || $tokens[$next][2] !== T_DOUBLE_ARROW) {
                continue;
            }

            $value = $this->next($tokens, $next);

            if ($value === null || $tokens[$value][0] !== '[') {
                throw new InvalidArgumentException("config/gadya-cms.php sets '{$key}' to something other than an array; add the destination by hand.");
            }

            return ['open' => $value, 'close' => $this->closing($tokens, $value)];
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int|null}>  $tokens
     * @return array{open: int, close: int}|null
     */
    private function rootArray(array $tokens): ?array
    {
        foreach ($tokens as $index => $token) {
            if ($token[2] === T_RETURN) {
                $open = $this->next($tokens, $index);

                return $open !== null && $tokens[$open][0] === '[' ? ['open' => $open, 'close' => $this->closing($tokens, $open)] : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int|null}>  $tokens
     */
    private function closing(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            if ($tokens[$i][0] === '[') {
                $depth++;
            } elseif ($tokens[$i][0] === ']' && --$depth === 0) {
                return $i;
            }
        }

        throw new InvalidArgumentException('config/gadya-cms.php has an array that never closes.');
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int|null}>  $tokens
     */
    private function next(array $tokens, int $index): ?int
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! in_array($tokens[$i][2], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Each token's text, offset and type (null for a single character).
     *
     * @return list<array{0: string, 1: int, 2: int|null}>
     */
    private function tokens(string $source): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $tokens[] = [$text, $offset, is_array($token) ? $token[0] : null];
            $offset += strlen($text);
        }

        return $tokens;
    }
}
