<?php

namespace Gadya\Cms\Console;

use Gadya\Cms\Forms\Builder\ConfigWriter;
use Gadya\Cms\Forms\Builder\ConvertConfigForm;
use Gadya\Cms\Forms\Builder\FormScanner;
use Gadya\Cms\Forms\Builder\TemplateValues;
use Gadya\Cms\Forms\Destinations\FormDestinations;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Privacy\Consent;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Makes a builder form from a configured form and/or the `<form>` in a
 * template, under the same name, as a draft. It never touches the
 * template, and touches config only with --write-config: it prints the
 * one line that replaces the markup, for whoever converts it to put in
 * once they have checked the form.
 *
 * A template's words written as lang keys come from the site's lang
 * files, in every language it has. A form that posted to the site's own
 * controller can keep doing what that controller did through a
 * destination: --write-config adds the one suggested from the
 * controller, --destination chooses it for the form.
 */
class ConvertFormCommand extends Command
{
    protected $signature = 'gadya-cms:forms:convert
        {form : The form\'s name (its slug)}
        {--from=config : config, or blade to read the fields from a template}
        {--file= : The template holding the form, for --from=blade}
        {--destination=* : Also save each enquiry to this destination (forms.builder.destinations), as the old controller did}
        {--write-config : Add the destination suggested from the form\'s controller to config/gadya-cms.php (shown as a diff with --dry-run)}
        {--dry-run : Show what would be built, and build nothing}
        {--json : Machine-readable output}';

    protected $description = 'Build a builder form from a configured form or a template\'s <form>, keeping its name so enquiries, emails and analytics carry on';

    public function handle(ConvertConfigForm $converter, FormScanner $scanner, FormDestinations $destinations, ConfigWriter $configWriter): int
    {
        $name = (string) $this->argument('form');
        $from = (string) $this->option('from');
        $hints = [];
        $words = [];
        $handler = null;
        $suggested = null;
        $ownRoute = false;
        $match = null;

        if (! in_array($from, ['config', 'blade'], true)) {
            return $this->fail('--from must be config or blade.');
        }

        if ($from === 'blade') {
            $file = (string) $this->option('file');
            $path = is_file($file) ? $file : base_path($file);

            if ($file === '' || ! is_file($path)) {
                return $this->fail('Give the template with --file=resources/views/...');
            }

            $forms = $scanner->formsIn((string) file_get_contents($path), (string) (realpath($path) ?: $path));
            $match = collect($forms)->firstWhere('name', $name) ?? (count($forms) === 1 ? $forms[0] : null);

            if ($match === null) {
                return $this->fail(count($forms) === 0 ? 'There is no <form> in that template.' : 'That template has several forms and none posts to "'.$name.'".');
            }

            $hints = $match['fields'];
            $ownRoute = $match['name'] === null && is_array($match['action'] ?? null);
            $read = $ownRoute ? $scanner->handler($match) : [];
            $handler = $read['handler'] ?? null;
            $suggested = $read['suggested_destination'] ?? null;
            $redirect = is_array($handler['redirect'] ?? null) ? $handler['redirect'] : [];
            $words = [
                'submit' => $match['submit'] ?? null,
                'success' => array_filter(['key' => $redirect['message_key'] ?? null, 'text' => $redirect['message'] ?? null]) ?: null,
            ];
        } elseif (FormDefinition::find($name) === null) {
            return $this->fail("There is no configured form called [{$name}]. Use --from=blade --file=... for a form in a template.");
        }

        try {
            $plan = $converter->plan($name, $hints, $words, withConfig: ! $ownRoute);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage());
        }

        /*
         * The destination the controller's record suggests goes into
         * config only when asked; a dry run shows the change instead.
         */
        $config = null;

        if ($this->option('write-config')) {
            if (! is_array($suggested)) {
                return $this->fail('There is no destination to write: --write-config needs a form (--from=blade) that posts to a controller creating a record.');
            }

            try {
                $config = $configWriter->plan($suggested['key'], $suggested['config']);
            } catch (InvalidArgumentException $exception) {
                return $this->fail($exception->getMessage());
            }

            if (! $this->option('dry-run') && $config['changed']) {
                $configWriter->write($suggested['key'], $suggested['config']);
            }

            /* This run already knows it, written or not, so --destination can name it. */
            if (! $destinations->has($suggested['key'])) {
                config(['gadya-cms.forms.builder.destinations.'.$suggested['key'] => $suggested['config']]);
            }
        }

        $chosen = array_values(array_unique(array_filter(array_map('strval', (array) $this->option('destination')))));

        foreach ($chosen as $key) {
            /* The one being written is there once this run is over, even if this process cannot load its model. */
            $writing = is_array($suggested) && $this->option('write-config') && $key === $suggested['key'];

            if (! $writing && ! $destinations->has($key)) {
                return $this->fail('There is no destination called "'.$key.'" under forms.builder.destinations.'.(is_array($suggested) ? ' Add --write-config to add the suggested "'.$suggested['key'].'".' : ''));
            }
        }

        /*
         * What the old form did around its questions, carried over: who the
         * controller emailed, the analytics event, and the privacy policy
         * its consent box linked to.
         */
        $notify = is_array($handler['notify'] ?? null) ? $handler['notify'] : ['emails' => [], 'from' => [], 'unresolved' => []];
        $analytics = is_array($match['analytics'] ?? null) ? $match['analytics'] : (is_string($handler['analytics_event'] ?? null) ? ['event' => $handler['analytics_event'], 'source' => 'the controller'] : null);
        $carried = array_filter([
            'notify' => ($plan['settings']['notify'] ?? []) === [] ? $notify['emails'] : [],
            'analytics_event' => ($plan['settings']['analytics_event'] ?? null) === null ? ($analytics['event'] ?? null) : null,
        ], fn ($value): bool => $value !== [] && $value !== null);
        $plan['settings'] = [...$plan['settings'], ...$carried];
        $consent = $this->consent($plan, $hints);
        $suggestedConfig = [];

        if (($consent['policy_url'] ?? null) !== null && blank(rescue(fn (): ?string => app(Consent::class)->published()['policy_url'], config('gadya-cms.privacy.policy_url'), report: false))) {
            $suggestedConfig['privacy.policy_url'] = $consent['policy_url'];
        }

        $unmapped = (array) ($match['unmapped'] ?? []);

        $exists = Form::query()->forCurrentSite()->where('slug', $plan['slug'])->exists();
        $replacement = '<x-gadya-cms::form form="'.$plan['slug'].'" />';

        if (! $this->option('dry-run') && ! $exists) {
            $form = $converter->convert($name, $hints, $words, [...$carried, ...($chosen === [] ? [] : ['destinations' => $chosen])], withConfig: ! $ownRoute);
        }

        $notices = $scanner->notices();

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
            'settings' => [...$plan['settings'], ...($chosen === [] ? [] : ['destinations' => $chosen])],
            'translations' => $plan['translations'],
            'messages' => $plan['messages'],
            'success' => array_filter([app(Locales::class)->default() => $plan['messages']['success'] ?? null, ...array_map(fn (array $overlay): ?string => $overlay['messages']['success'] ?? null, $plan['translations'])]),
            'notify' => $notify,
            'analytics' => $analytics,
            'consent' => $consent,
            'suggested_config' => $suggestedConfig,
            'unmapped' => $unmapped,
            'lang_matches' => $plan['lang_matches'] ?? [],
            'handler' => $handler,
            'suggested_destination' => $suggested,
            'config' => $config === null ? null : [
                'path' => 'config/gadya-cms.php',
                'written' => ! $this->option('dry-run') && $config['changed'],
                'already_there' => ! $config['changed'],
                'diff' => $config['changed'] ? ConfigWriter::diff($config['before'], $config['after']) : null,
            ],
            'warnings' => $plan['warnings'],
            'notices' => $notices,
            'replacement' => $replacement,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exists && ! $this->option('dry-run') ? self::FAILURE : self::SUCCESS;
        }

        foreach ($result['fields'] as $field) {
            $this->components->twoColumnDetail($field['key'].' <fg=gray>'.$field['type'].(($field['required'] ?? false) ? ', required' : '').'</>', (string) ($field['label'] ?? ''));
        }

        foreach ($plan['translations'] as $locale => $overlay) {
            $this->components->twoColumnDetail('<fg=gray>translation</> '.$locale, count($overlay['fields'] ?? []).' '.str('question')->plural(count($overlay['fields'] ?? [])).', '.count($overlay['messages'] ?? []).' '.str('message')->plural(count($overlay['messages'] ?? [])).' from the lang files');
        }

        foreach ($handler['does'] ?? [] as $line) {
            $this->line('  The old controller: '.$line);
        }

        $this->components->twoColumnDetail('<options=bold>Check before building</>');
        $this->components->twoColumnDetail('  Staff emails', $result['settings']['notify'] === [] ? '<fg=yellow>none</>' : implode(', ', $result['settings']['notify']).($notify['from'] === [] ? '' : ' <fg=gray>(from '.implode(', ', $notify['from']).')</>'));

        foreach ($notify['unresolved'] as $expression) {
            $this->components->warn('The controller emails '.$expression.', which is not set here: add the address to the form\'s staff emails by hand.');
        }

        $this->components->twoColumnDetail('  Analytics event', $analytics === null ? '<fg=yellow>none found</>' : $analytics['event'].' <fg=gray>(from '.$analytics['source'].')</>');

        foreach ($result['success'] as $locale => $text) {
            $this->components->twoColumnDetail('  Thank-you ('.$locale.')', $text);
        }

        foreach ($consent['wording'] ?? [] as $locale => $text) {
            $this->components->twoColumnDetail('  Consent ('.$locale.')', $text);
        }

        foreach ($suggestedConfig as $key => $value) {
            $this->components->twoColumnDetail('  Suggested config', $key.' => '.$value.' <fg=gray>(or Settings → Privacy choices)</>');
        }

        foreach ($plan['lang_matches'] ?? [] as $matched) {
            $this->components->twoColumnDetail('  <fg=gray>matched</> "'.$matched['text'].'"', 'lang key '.$matched['key']);
        }

        foreach ($unmapped as $item) {
            $this->components->warn('"'.$item['field'].'": '.$item['note']);
        }

        if ($config !== null) {
            if (! $config['changed']) {
                $this->components->info('config/gadya-cms.php already has the destination "'.$suggested['key'].'".');
            } else {
                $this->line(ConfigWriter::diff($config['before'], $config['after']));
                $this->components->info($this->option('dry-run') ? 'Dry run: config/gadya-cms.php was not changed.' : 'Added the destination "'.$suggested['key'].'" to config/gadya-cms.php. Check the mapping.');
            }
        } elseif (is_array($suggested)) {
            $this->components->info('The controller creates a '.class_basename($suggested['config']['model']).'. Add --destination='.$suggested['key'].' --write-config to keep doing so.');
        }

        foreach ([...$plan['warnings'], ...$notices] as $warning) {
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

    /**
     * The consent question's words in every language, and the address of
     * the privacy policy its box linked to.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $hints
     * @return array{field: string, wording: array<string, string>, policy_url: string|null, link: string|null}|null
     */
    private function consent(array $plan, array $hints): ?array
    {
        $field = collect($plan['fields'])->firstWhere('type', 'consent');

        if (! is_array($field)) {
            return null;
        }

        $wording = [app(Locales::class)->default() => $field['label']];

        foreach ($plan['translations'] as $locale => $overlay) {
            $label = collect($overlay['fields'] ?? [])->firstWhere('key', $field['key'])['label'] ?? null;

            if (is_string($label)) {
                $wording[$locale] = $label;
            }
        }

        $link = $hints[$field['key']]['links'][0] ?? null;
        $url = null;

        if (is_string($link)) {
            $expression = preg_match('/^\s*\{\{(.*)\}\}\s*$/s', $link, $echo) === 1 ? trim($echo[1]) : null;
            $value = $expression === null ? ['ok' => true, 'value' => $link] : app(TemplateValues::class)->evaluate($expression);
            $url = $value['ok'] && is_string($value['value']) && $value['value'] !== '' ? $value['value'] : null;

            /* The site's own page: kept as its path, so it works on every host. */
            if ($url !== null && in_array(parse_url($url, PHP_URL_HOST), [parse_url((string) config('app.url'), PHP_URL_HOST), parse_url(url('/'), PHP_URL_HOST)], true)) {
                $url = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
            }
        }

        return ['field' => $field['key'], 'wording' => $wording, 'policy_url' => $url, 'link' => $link];
    }
}
