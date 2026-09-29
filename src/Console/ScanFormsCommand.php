<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Forms\Builder\ConfigWriter;
use Gadya\Cms\Forms\Builder\FormScanner;
use Illuminate\Console\Command;

/**
 * Every form on the site, for an agent converting them: configured ones,
 * `<form>` markup in the templates, Livewire components - and, for a form
 * that posts to the site's own controller, what that controller does and
 * a destination that would do the same.
 */
class ScanFormsCommand extends Command
{
    protected $signature = 'gadya-cms:forms:scan {--json : Machine-readable output, for an agent converting the forms}';

    protected $description = 'Find every form on the site - configured, in templates, or in Livewire components - with its fields, ready to convert to a builder form';

    public function handle(FormScanner $scanner): int
    {
        $found = $scanner->scan();
        $notices = $scanner->notices();

        if ($this->option('json')) {
            $this->line((string) json_encode(['forms' => $found, 'notices' => $notices], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($notices as $notice) {
            $this->components->warn($notice);
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

            if (is_array($form['handler'] ?? null)) {
                $this->components->twoColumnDetail('  <fg=yellow>posts to</>', $form['handler']['action'].(($form['handler']['file'] ?? null) ? ' ('.$form['handler']['file'].(($form['handler']['line'] ?? 0) > 0 ? ':'.$form['handler']['line'] : '').')' : ''));

                foreach ($form['handler']['does'] ?? [] as $line) {
                    $this->line('    - '.$line);
                }
            }

            $destination = $form['suggested_destination'] ?? null;

            if (is_array($destination)) {
                $this->line('    Suggested destination "'.$destination['key'].'" for config/gadya-cms.php (forms.builder.destinations):');
                $this->line((string) preg_replace('/^/m', '      ', ConfigWriter::export([$destination['key'] => $destination['config']])));

                foreach ($destination['unmapped'] as $attribute => $expression) {
                    $this->components->warn('"'.$attribute.'" is set from '.$expression.', which no token covers: map it by hand or leave it to a default.');
                }
            }

            $command = 'php artisan gadya-cms:forms:convert '.($form['name'] ?? $form['suggested_slug']).($form['kind'] === 'blade' ? ' --from=blade --file='.$form['file'] : '').(is_array($destination) ? ' --destination='.$destination['key'].' --write-config' : '');
            $this->components->twoColumnDetail('  <fg=gray>builder form</>', $form['builder'] ? 'already built' : 'convert with: '.$command);
        }

        return self::SUCCESS;
    }
}
