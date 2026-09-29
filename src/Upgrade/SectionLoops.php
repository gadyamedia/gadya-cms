<?php

namespace Gadya\Cms\Upgrade;

use Illuminate\Filesystem\Filesystem;

/**
 * The places a site's templates loop over a page's sections, and whether
 * each one lets the package draw the sections it knows (a Form section)
 * with `@cmsSection`.
 *
 * Only a loop written the ordinary way is recognised -
 * `@foreach ($page['sections'] ?? [] as $index => $section)` alone on its
 * line - so the upgrade step that adds the line never guesses at markup it
 * does not understand; anything else is left for a person, with the audit
 * saying what to add.
 */
class SectionLoops
{
    private const LOOP = '/^(\s*)@foreach\s*\((.*\bsections\b.*?)\s+as\s+(\$\w+)(?:\s*=>\s*(\$\w+))?\s*\)\s*$/';

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return list<array{file: string, line: int, section: string, index: string|null, wired: bool}>
     */
    public function find(?string $root = null): array
    {
        $root ??= resource_path('views');

        if (! $this->files->isDirectory($root)) {
            return [];
        }

        $loops = [];

        foreach ($this->files->allFiles($root) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $lines = explode("\n", $file->getContents());

            foreach ($lines as $number => $line) {
                if (preg_match(self::LOOP, $line, $match) !== 1) {
                    continue;
                }

                $next = trim($this->nextLine($lines, $number));

                $loops[] = [
                    'file' => $file->getPathname(),
                    'line' => $number + 1,
                    'section' => ($match[4] ?? '') !== '' ? $match[4] : $match[3],
                    'index' => ($match[4] ?? '') !== '' ? $match[3] : null,
                    'wired' => str_starts_with($next, '@cmsSection'),
                ];
            }
        }

        return $loops;
    }

    /**
     * Whether the templates say anything about sections that the loop
     * finder would miss: a loop written some other way, or a partial.
     */
    public function mentionsSections(?string $root = null): bool
    {
        $root ??= resource_path('views');

        if (! $this->files->isDirectory($root)) {
            return false;
        }

        foreach ($this->files->allFiles($root) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && preg_match("/\\[['\"]sections['\"]\\]/", $file->getContents()) === 1) {
                return true;
            }
        }

        return false;
    }

    public function anyWired(?string $root = null): bool
    {
        $root ??= resource_path('views');

        if (! $this->files->isDirectory($root)) {
            return false;
        }

        foreach ($this->files->allFiles($root) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && str_contains($file->getContents(), '@cmsSection(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add `@cmsSection(...)` as the first line inside every recognised
     * loop that lacks it.
     *
     * @return list<string> The files changed
     */
    public function wire(?string $root = null): array
    {
        $changed = [];

        foreach (collect($this->find($root))->reject(fn (array $loop): bool => $loop['wired'])->groupBy('file') as $file => $loops) {
            $lines = explode("\n", $this->files->get($file));

            foreach ($loops->sortByDesc('line') as $loop) {
                $indent = preg_match('/^(\s*)/', $lines[$loop['line'] - 1], $match) === 1 ? $match[1] : '';
                $call = '@cmsSection('.$loop['section'].($loop['index'] !== null ? ', '.$loop['index'] : '').')';

                array_splice($lines, $loop['line'], 0, [$indent.'    '.$call]);
            }

            $this->files->put($file, implode("\n", $lines));
            $changed[] = $file;
        }

        return $changed;
    }

    /**
     * @param  list<string>  $lines
     */
    private function nextLine(array $lines, int $number): string
    {
        for ($next = $number + 1; $next < count($lines); $next++) {
            if (trim($lines[$next]) !== '') {
                return $lines[$next];
            }
        }

        return '';
    }
}
