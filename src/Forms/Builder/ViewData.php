<?php

namespace Gadya\Cms\Forms\Builder;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Where a template's variables come from: the controller or route that
 * renders it (`view('pages.contact', [...])`, `compact('services')`,
 * `->with('services', ...)`, `Route::view(...)`) or a view composer
 * (`View::composer('pages.contact', ...)`). Each variable is given as
 * the expression that makes it, with the `use` statements of the file
 * it is in, for TemplateValues to work out. Source is read, never run.
 */
class ViewData
{
    public function __construct(private readonly Filesystem $files) {}

    /**
     * The dotted name of a template, from its path.
     */
    public function viewName(string $path): ?string
    {
        $real = realpath($path) ?: $path;
        $roots = array_filter([...(array) config('view.paths', []), rescue(fn (): string => resource_path('views'), null, report: false)]);

        foreach ($roots as $root) {
            $root = rtrim(realpath((string) $root) ?: (string) $root, '/').'/';

            if (str_starts_with($real, $root)) {
                return str_replace('/', '.', Str::beforeLast(substr($real, strlen($root)), '.blade.php'));
            }
        }

        /* A template outside the view paths: everything after its views folder. */
        if (preg_match('#/views/(.+)\.blade\.php$#', $real, $match) === 1) {
            return str_replace('/', '.', $match[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $directories  Where to look: the app, its routes
     * @return array<string, array{expression: string, uses: array<string, string>, file: string}> variable => where it comes from
     */
    public function for(string $view, array $directories): array
    {
        $found = [];
        $quoted = preg_quote($view, '/');

        foreach ($this->phpFiles($directories) as $path) {
            $source = (string) $this->files->get($path);

            if (! str_contains($source, $view)) {
                continue;
            }

            $uses = PhpSource::uses($source);
            $add = function (string $name, string $expression, string $scope) use (&$found, $uses, $path): void {
                $expression = trim($expression);

                /* compact('services') and ['services' => $services]: the variable is made just before. */
                if (preg_match('/^\$(\w+)$/', $expression, $variable) === 1) {
                    $expression = (string) PhpSource::assignment($scope, $variable[1]);
                }

                if ($expression !== '') {
                    $found[$name] ??= ['expression' => $expression, 'uses' => $uses, 'file' => $path];
                }
            };

            preg_match_all('/(?:\bview|View::make|->view|Route::view|View::composer|->composer|View::creator)\s*\(/', $source, $calls, PREG_OFFSET_CAPTURE);

            foreach ($calls[0] as [$text, $offset]) {
                $open = $offset + strlen($text) - 1;
                $close = PhpSource::closing($source, $open);
                $arguments = PhpSource::arguments($source, $open);
                $isRouteView = str_starts_with($text, 'Route::view');
                $isComposer = str_contains($text, 'composer') || str_contains($text, 'creator');
                $names = $arguments[$isRouteView ? 1 : 0] ?? '';

                if ($close === null || preg_match('/([\'"])'.$quoted.'\1/', $names) !== 1) {
                    continue;
                }

                $scope = PhpSource::enclosingFunction($source, $offset);
                $data = $arguments[$isRouteView ? 2 : 1] ?? null;

                if ($isComposer) {
                    /* The composer's callback: every ->with() inside it. */
                    $data = null;
                    $scope = substr($source, $open, $close - $open + 1);
                    $this->withCalls($scope, 0, $add, $scope);

                    continue;
                }

                if (is_string($data)) {
                    foreach (PhpSource::pairs($data) ?? [] as $name => $expression) {
                        $add($name, $expression, $scope);
                    }

                    if (preg_match('/^compact\((.*)\)$/s', $data, $compact) === 1) {
                        preg_match_all('/[\'"](\w+)[\'"]/', $compact[1], $variables);

                        foreach ($variables[1] as $variable) {
                            $add($variable, '$'.$variable, $scope);
                        }
                    }
                }

                $this->withCalls($source, $close + 1, $add, $scope, chained: true);
            }
        }

        return $found;
    }

    /**
     * `->with('name', ...)` and `->with([...])`, in a chain from an offset
     * (only the chain straight after it, when `$chained`).
     *
     * @param  callable(string, string, string): void  $add
     */
    private function withCalls(string $source, int $from, callable $add, string $scope, bool $chained = false): void
    {
        $offset = $from;

        while (preg_match('/'.($chained ? '\G\s*' : '').'->with\s*\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $open = $match[0][1] + strlen($match[0][0]) - 1;
            $close = PhpSource::closing($source, $open);
            $arguments = PhpSource::arguments($source, $open);

            if ($close === null) {
                return;
            }

            if (count($arguments) === 2 && preg_match('/^([\'"])(\w+)\1$/', $arguments[0], $name) === 1) {
                $add($name[2], $arguments[1], $scope);
            } elseif (count($arguments) === 1) {
                foreach (PhpSource::pairs($arguments[0]) ?? [] as $name => $expression) {
                    $add($name, $expression, $scope);
                }
            }

            $offset = $close + 1;
        }
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private function phpFiles(array $directories): array
    {
        $paths = [];

        foreach ($directories as $directory) {
            if (! $this->files->isDirectory($directory)) {
                continue;
            }

            foreach ($this->files->allFiles($directory) as $file) {
                if ($file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return array_values(array_unique($paths));
    }
}
