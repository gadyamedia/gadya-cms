<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Forms\Builder\ConvertConfigForm;
use Gadya\Cms\Forms\Builder\FormScanner;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\Form;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Makes a builder form from a configured form and/or the `<form>` in a
 * template, under the same name, as a draft. It never touches the
 * template or the configuration: it prints the one line that replaces
 * the markup, for whoever converts it to put in once they have checked
 * the form.
 */
class ConvertFormCommand extends Command
{
    protected $signature = 'gadya-cms:forms:convert
        {form : The form\'s name (its slug)}
        {--from=config : config, or blade to read the fields from a template}
        {--file= : The template holding the form, for --from=blade}
        {--dry-run : Show what would be built, and build nothing}
        {--json : Machine-readable output}';

    protected $description = 'Build a builder form from a configured form or a template\'s <form>, keeping its name so enquiries, emails and analytics carry on';

    public function handle(ConvertConfigForm $converter, FormScanner $scanner): int
    {
        $name = (string) $this->argument('form');
        $from = (string) $this->option('from');
        $hints = [];

        if (! in_array($from, ['config', 'blade'], true)) {
            return $this->fail('--from must be config or blade.');
        }

        if ($from === 'blade') {
            $file = (string) $this->option('file');
            $path = is_file($file) ? $file : base_path($file);

            if ($file === '' || ! is_file($path)) {
                return $this->fail('Give the template with --file=resources/views/...');
            }

            $forms = $scanner->formsIn((string) file_get_contents($path));
            $match = collect($forms)->firstWhere('name', $name) ?? (count($forms) === 1 ? $forms[0] : null);

            if ($match === null) {
                return $this->fail(count($forms) === 0 ? 'There is no <form> in that template.' : 'That template has several forms and none posts to "'.$name.'".');
            }

            $hints = $match['fields'];
        } elseif (FormDefinition::find($name) === null) {
            return $this->fail("There is no configured form called [{$name}]. Use --from=blade --file=... for a form in a template.");
        }

        try {
            $plan = $converter->plan($name, $hints);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage());
        }

        $exists = Form::query()->forCurrentSite()->where('slug', $plan['slug'])->exists();
        $replacement = '<x-gadya-cms::form form="'.$plan['slug'].'" />';

        if (! $this->option('dry-run') && ! $exists) {
            $form = $converter->convert($name, $hints);
        }

        $result = [
            'dry_run' => (bool) $this->option('dry-run'),
            'created' => isset($form),
            'already_exists' => $exists,
            'slug' => $plan['slug'],
            'title' => $plan['title'],
            'status' => isset($form) ? $form->status : null,
            'fields' => array_map(fn (array $field): array => array_filter([
                'key' => $field['key'],
                'type' => $field['type'],
                'label' => $field['label'],
                'required' => $field['required'],
                'options' => array_column($field['options'], 'label', 'key'),
            ], fn ($value): bool => $value !== [] && $value !== ''), $plan['fields']),
            'settings' => $plan['settings'],
            'warnings' => $plan['warnings'],
            'replacement' => $replacement,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exists && ! $this->option('dry-run') ? self::FAILURE : self::SUCCESS;
        }

        foreach ($result['fields'] as $field) {
            $this->components->twoColumnDetail($field['key'].' <fg=gray>'.$field['type'].(($field['required'] ?? false) ? ', required' : '').'</>', (string) ($field['label'] ?? ''));
        }

        foreach ($plan['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        if ($exists) {
            $this->components->error('There is already a builder form called "'.$plan['slug'].'". Nothing was built.');

            return $this->option('dry-run') ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was built. Run again without --dry-run to build it as a draft.');
        } else {
            $this->components->info('Built "'.$plan['title'].'" as a draft. The original keeps answering until it is published under Content → Forms.');
        }

        $this->line('Replace the <form> in the template with:');
        $this->line('  '.$replacement);

        return self::SUCCESS;
    }
}
