<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Forms\Builder\FormScanner;
use Illuminate\Console\Command;

class ScanFormsCommand extends Command
{
    protected $signature = 'gadya-cms:forms:scan {--json : Machine-readable output, for an agent converting the forms}';

    protected $description = 'Find every form on the site - configured, in templates, or in Livewire components - with its fields, ready to convert to a builder form';

    public function handle(FormScanner $scanner): int
    {
        $found = $scanner->scan();

        if ($this->option('json')) {
            $this->line((string) json_encode(['forms' => $found], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($found === []) {
            $this->components->info('No forms found.');

            return self::SUCCESS;
        }

        $this->components->info(count($found).' '.str('form')->plural(count($found)).' found');

        foreach ($found as $form) {
            $this->components->twoColumnDetail(
                '<options=bold>'.($form['name'] ?? '(no package form)').'</> <fg=gray>'.$form['kind'].'</>',
                $form['file'].($form['line'] > 0 ? ':'.$form['line'] : ''),
            );

            foreach ($form['fields'] as $name => $field) {
                $this->components->twoColumnDetail(
                    '  '.$name,
                    implode(' · ', array_filter([
                        $field['type'] ?? null,
                        isset($field['rules']) ? implode('|', $field['rules']) : null,
                        ($field['required'] ?? false) ? 'required' : null,
                        $field['label'] ?? null,
                    ])),
                );
            }

            $this->components->twoColumnDetail('  <fg=gray>builder form</>', $form['builder'] ? 'already built' : 'convert with: php artisan gadya-cms:forms:convert '.($form['name'] ?? $form['suggested_slug']).($form['kind'] === 'blade' ? ' --from=blade --file='.$form['file'] : ''));
        }

        return self::SUCCESS;
    }
}
