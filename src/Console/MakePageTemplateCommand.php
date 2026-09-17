<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Content\EditableFields;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scaffolds a page template with every configured content field already
 * marked editable, the head tags in place, and the sections loop written
 * out - so a new page type starts correct rather than being copied from
 * the last one and half-fixed.
 */
class MakePageTemplateCommand extends Command
{
    protected $signature = 'gadya-cms:make:page-template
        {name : The view name, e.g. pages/types/menu or pages.show}
        {--layout= : The layout to extend (defaults to gadya-cms.blog.layout)}
        {--force : Overwrite an existing file}';

    protected $description = 'Scaffold a Blade page template wired to the live editor';

    public function handle(EditableFields $fields): int
    {
        $name = str_replace('.', '/', trim($this->argument('name'), '/'));
        $path = resource_path('views/'.Str::finish($name, '.blade.php'));

        if (File::exists($path) && ! $this->option('force')) {
            $this->components->error("{$path} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->render((string) ($this->option('layout') ?: config('gadya-cms.blog.layout', 'layouts.app')), $fields));

        $this->components->info('Template written to '.Str::after($path, base_path().'/'));
        $this->line('  '.'→'.' '.'Pass $page, $site and $slug from your controller; see vendor/gadya/cms/docs/site-document.md.');

        $missing = $this->fieldsNotEditable($fields);

        if ($missing !== []) {
            $this->components->warn('These fields are not in gadya-cms.editable_fields yet, so the editor will ignore them: '.implode(', ', $missing));
        }

        return self::SUCCESS;
    }

    private function render(string $layout, EditableFields $fields): string
    {
        $stub = (string) File::get(__DIR__.'/../../stubs/page-template.blade.stub');

        return str_replace(
            ['{{ layout }}', '{{ fields }}'],
            [$layout, $this->fieldMarkup()],
            $stub,
        );
    }

    private function fieldMarkup(): string
    {
        $lines = [];

        foreach ((array) config('gadya-cms.pages.content_fields', []) as $name => $field) {
            if (! is_array($field) || ! is_string($name)) {
                continue;
            }

            $lines[] = match ($field['type'] ?? 'text') {
                'image' => "        <img class=\"page-{$name}\" src=\"@siteImage(\$page['{$name}'] ?? '')\" alt=\"\" @editable('{$name}', 'image')>",
                'textarea' => "        <p class=\"page-{$name}\" @editable('{$name}', 'multiline')>{{ \$page['{$name}'] ?? '' }}</p>",
                default => $name === 'heading'
                    ? "        <h1 class=\"page-{$name}\" @editable('{$name}')>{{ \$page['{$name}'] ?? \$page['title'] }}</h1>"
                    : "        <p class=\"page-{$name}\" @editable('{$name}')>{{ \$page['{$name}'] ?? '' }}</p>",
            };
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function fieldsNotEditable(EditableFields $fields): array
    {
        $missing = [];

        foreach (array_keys((array) config('gadya-cms.pages.content_fields', [])) as $name) {
            if (! $fields->allows('pages.example.'.$name)) {
                $missing[] = (string) $name;
            }
        }

        return $missing;
    }
}
