<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Filesystem\Filesystem;

/**
 * Finds a named route in the site's `routes/*.php` by reading them, for
 * when the running router does not have it: the name is put together
 * through every group around it (`->name('avant.')`, `'as' => ...`, a
 * name built from a variable such as `"localized.{$locale}."`), so a
 * route whose address is worked out in code (`$uri('contact.submit')`)
 * is still found by the name the form uses. Its action may be
 * `ContactController::class` (invokable), `[ContactController::class]`,
 * `[ContactController::class, 'send']`, `'ContactController@send'` or a
 * class name in a string.
 */
class RoutesFile
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return array{class: string, method: string, file: string, line: int, name: string}|null
     */
    public function find(string $name, string $directory): ?array
    {
        if (! $this->files->isDirectory($directory)) {
            return null;
        }

        $found = [];

        foreach ($this->files->allFiles($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach ($this->routes((string) $this->files->get($file->getPathname()), $file->getPathname()) as $route) {
                if ($route['pattern'] !== null && preg_match($route['pattern'], $name) === 1) {
                    $found[] = $route;
                }
            }
        }

        /* A route written without a variable in its name first, then a form's own verb. */
        usort($found, fn (array $a, array $b): int => [! $a['exact'], ! $a['posts']] <=> [! $b['exact'], ! $b['posts']]);
        $route = $found[0] ?? null;

        return $route === null ? null : [
            'class' => $route['class'],
            'method' => $route['method'],
            'file' => $route['file'],
            'line' => $route['line'],
            'name' => $name,
        ];
    }

    /**
     * Every route in one file that names a controller, with a pattern for
     * its full name.
     *
     * @return list<array{pattern: string|null, exact: bool, posts: bool, class: string, method: string, file: string, line: int}>
     */
    private function routes(string $source, string $path): array
    {
        $uses = PhpSource::uses($source);
        $groups = $this->groups($source);
        $routes = [];

        preg_match_all('/\bRoute::(get|post|put|patch|delete|any|match|options)\s*\(/i', $source, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($calls as $call) {
            $open = $call[0][1] + strlen($call[0][0]) - 1;
            $close = PhpSource::closing($source, $open);

            if ($close === null) {
                continue;
            }

            $arguments = PhpSource::arguments($source, $open);
            $verb = strtolower($call[1][0]);
            $action = $this->action($arguments[$verb === 'match' ? 2 : 1] ?? '', $uses);

            if ($action === null) {
                continue;
            }

            $chain = PhpSource::expression($source, $close + 1);
            $own = preg_match('/->name\(\s*([\'"])(.*?)\1\s*\)/s', $chain, $match) === 1 ? $match[2] : null;

            if ($own === null) {
                continue;
            }

            $prefix = '';

            foreach ($groups as $group) {
                if ($group['start'] < $call[0][1] && $group['end'] > $call[0][1]) {
                    $prefix .= $group['name'];
                }
            }

            $full = $prefix.$own;

            $routes[] = [
                'pattern' => $this->pattern($full),
                'exact' => preg_match('/\$|\{/', $full) !== 1,
                'posts' => in_array($verb, ['post', 'put', 'patch', 'any', 'match'], true),
                'class' => $action['class'],
                'method' => $action['method'],
                'file' => $path,
                'line' => substr_count(substr($source, 0, $call[0][1]), "\n") + 1,
            ];
        }

        return $routes;
    }

    /**
     * Route groups and the name each gives the routes inside, outermost
     * first.
     *
     * @return list<array{start: int, end: int, name: string}>
     */
    private function groups(string $source): array
    {
        $groups = [];

        preg_match_all('/->group\s*\(|Route::group\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [$text, $offset]) {
            $open = $offset + strlen($text) - 1;
            $close = PhpSource::closing($source, $open);

            if ($close === null) {
                continue;
            }

            $inside = substr($source, $open, $close - $open + 1);
            $name = '';

            if (str_starts_with($text, 'Route::group')) {
                $arguments = PhpSource::arguments($source, $open);
                $name = preg_match('/[\'"]as[\'"]\s*=>\s*([\'"])(.*?)\1/', $arguments[0] ?? '', $match) === 1 ? $match[2] : '';
            } else {
                /* The chain this ->group( ends, from the `Route::` that starts it. */
                $before = substr($source, 0, $offset);
                $statement = substr($before, (int) strrpos($before, 'Route::'));

                if (preg_match_all('/(?:->|::)(?:name|as)\(\s*([\'"])(.*?)\1\s*\)/s', $statement, $names, PREG_SET_ORDER) > 0) {
                    $name = implode('', array_map(fn (array $one): string => $one[2], $names));
                }
            }

            if (preg_match('/function\s*\([^)]*\)[^{]*\{/', $inside, $body, PREG_OFFSET_CAPTURE) === 1) {
                $groups[] = ['start' => $open + $body[0][1], 'end' => $close, 'name' => $name];
            }
        }

        usort($groups, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $groups;
    }

    /**
     * @param  array<string, string>  $uses
     * @return array{class: string, method: string}|null
     */
    private function action(string $argument, array $uses): ?array
    {
        $argument = trim($argument);
        $class = fn (string $name): string => str_contains(ltrim($name, '\\'), '\\') || ! isset($uses[ltrim($name, '\\')])
            ? (str_contains(ltrim($name, '\\'), '\\') ? ltrim($name, '\\') : 'App\\Http\\Controllers\\'.ltrim($name, '\\'))
            : $uses[ltrim($name, '\\')];

        return match (true) {
            preg_match('/^\[\s*(\\\\?[\w\\\\]+)::class\s*,\s*[\'"](\w+)[\'"]\s*\]$/', $argument, $match) === 1 => ['class' => $class($match[1]), 'method' => $match[2]],
            preg_match('/^\[\s*(\\\\?[\w\\\\]+)::class\s*,?\s*\]$/', $argument, $match) === 1,
            preg_match('/^(\\\\?[\w\\\\]+)::class$/', $argument, $match) === 1 => ['class' => $class($match[1]), 'method' => '__invoke'],
            preg_match('/^([\'"])(\\\\?[\w\\\\]+)@(\w+)\1$/', $argument, $match) === 1 => ['class' => $class(str_replace('\\\\', '\\', $match[2])), 'method' => $match[3]],
            preg_match('/^([\'"])(\\\\?[A-Z][\w\\\\]+)\1$/', $argument, $match) === 1 => ['class' => $class(str_replace('\\\\', '\\', $match[2])), 'method' => '__invoke'],
            default => null,
        };
    }

    /** A route name as a pattern, a part built from a variable matching any one segment's worth. */
    private function pattern(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $pieces = preg_split('/(\{\$[^}]+\}|\$\w+(?:->\w+|\[[^\]]*\])*)/', $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$name];
        $pattern = '';

        foreach ($pieces as $piece) {
            $pattern .= preg_match('/^(\{\$|\$)/', $piece) === 1 ? '[^.]+' : preg_quote($piece, '/');
        }

        return '/^'.$pattern.'$/';
    }
}
