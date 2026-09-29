<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\Form;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Every form a site already has, wherever it lives: in the configuration,
 * as `<form>` markup in its templates, or as a Livewire component that
 * hands its answers to StoreFormSubmission - with the fields each asks
 * for, so it can be converted into a builder form.
 *
 * Templates are read, never run: the fields are found by looking at the
 * markup, so anything drawn in a loop or a component is reported as far
 * as it can be seen, and the person converting it checks the rest.
 */
class FormScanner
{
    /** Names every package form posts that are not questions. */
    private const MACHINERY = ['_token', '_method', '_path', '_redirect', '_t', '_started', '_step', '_locale', '_resume', '_attribution', 'cf-turnstile-response'];

    public function __construct(private readonly Filesystem $files) {}

    /**
     * @return list<array{kind: string, name: string|null, file: string, line: int, fields: array<string, array<string, mixed>>, config: array<string, mixed>|null, builder: bool, suggested_slug: string}>
     */
    public function scan(?string $views = null, ?string $app = null): array
    {
        $views ??= resource_path('views');
        $app ??= app_path();
        $built = rescue(fn (): array => Form::query()->forCurrentSite()->pluck('status', 'slug')->all(), [], report: false);
        $found = [];

        foreach (FormDefinition::configLabels() as $name => $label) {
            $definition = FormDefinition::find($name);

            $found[] = [
                'kind' => 'config',
                'name' => $name,
                'file' => 'config/gadya-cms.php',
                'line' => $this->configLine($name),
                'fields' => array_map(fn ($rules): array => ['rules' => is_string($rules) ? explode('|', $rules) : array_map(fn ($rule): string => is_string($rule) ? $rule : (string) (method_exists($rule, '__toString') ? $rule : get_class($rule)), (array) $rules)], $definition?->rules ?? []),
                'config' => ['label' => $label, 'notify' => $definition?->notify ?? [], 'success' => $definition?->success, 'analytics_event' => $definition?->analyticsEvent],
                'builder' => isset($built[$name]),
                'suggested_slug' => Str::slug($name),
            ];
        }

        foreach ($this->bladeFiles($views) as $path) {
            foreach ($this->formsIn((string) $this->files->get($path)) as $form) {
                $name = $form['name'];
                $slug = Str::slug($name ?? Str::before(basename($path), '.blade.php').'-form');

                $found[] = [
                    'kind' => 'blade',
                    'name' => $name,
                    'file' => $this->relative($path),
                    'line' => $form['line'],
                    'fields' => $form['fields'],
                    'config' => $name !== null && isset(FormDefinition::configLabels()[$name]) ? ['label' => FormDefinition::configLabels()[$name]] : null,
                    'builder' => ($built[$name ?? $slug] ?? null) !== null,
                    'suggested_slug' => $slug,
                    ...($name === null ? $this->handler($form, $app) : []),
                ];
            }
        }

        foreach ($this->phpFiles($app) as $path) {
            $contents = (string) $this->files->get($path);

            if (! str_contains($contents, 'StoreFormSubmission')) {
                continue;
            }

            preg_match('/FormDefinition::find\(\s*[\'"]([a-z0-9_-]+)[\'"]/', $contents, $match);
            $name = $match[1] ?? null;
            $line = substr_count(Str::before($contents, 'StoreFormSubmission::class') !== $contents ? Str::before($contents, 'StoreFormSubmission::class') : Str::before($contents, 'StoreFormSubmission'), "\n") + 1;

            $found[] = [
                'kind' => 'livewire',
                'name' => $name,
                'file' => $this->relative($path),
                'line' => $line,
                'fields' => $this->livewireRules($contents),
                'config' => $name !== null && isset(FormDefinition::configLabels()[$name]) ? ['label' => FormDefinition::configLabels()[$name]] : null,
                'builder' => $name !== null && isset($built[$name]),
                'suggested_slug' => Str::slug($name ?? Str::kebab(basename($path, '.php'))),
            ];
        }

        return $found;
    }

    /**
     * For a form that posts to the site's own route: the controller action
     * behind it, what it does beyond keeping and emailing the enquiry, and
     * a destination for config that would do the same.
     *
     * @param  array<string, mixed>  $form  From formsIn()
     * @return array{action?: array<string, string>|null, handler?: array<string, mixed>|null, suggested_destination?: array<string, mixed>|null}
     */
    public function handler(array $form, ?string $app = null): array
    {
        $action = $form['action'] ?? null;

        if (! is_array($action)) {
            return ['action' => null, 'handler' => null, 'suggested_destination' => null];
        }

        $reader = app(ControllerReader::class);
        $resolved = rescue(fn (): ?array => $reader->resolve($action, $app), null, report: false);

        if ($resolved === null || $resolved['file'] === null) {
            return ['action' => $action, 'handler' => $resolved === null ? null : [...$resolved, 'found' => false, 'does' => ['The controller\'s file could not be found; read '.$resolved['action'].' by hand.']], 'suggested_destination' => null];
        }

        $fields = array_keys((array) ($form['fields'] ?? []));
        $analysis = $reader->read($resolved['file'], $resolved['class'], $resolved['method'], $fields);
        $suggestion = $reader->suggest($analysis, $fields);

        return [
            'action' => $action,
            'handler' => [
                ...$resolved,
                ...$analysis,
                'file' => ControllerReader::tidy($resolved['file']),
            ],
            'suggested_destination' => $suggestion,
        ];
    }

    /**
     * Things about the site that stop a converted form working, for
     * whoever converts it to put right: today, a panel with the forms
     * switched off (`->forms(false)`), whose enquiries would have nowhere
     * to be read.
     *
     * @return list<string>
     */
    public function notices(?string $app = null): array
    {
        $notices = [];

        foreach ($this->phpFiles($app ?? app_path()) as $path) {
            $contents = (string) $this->files->get($path);

            if (preg_match('/->forms\(\s*(?:condition:\s*)?false\s*\)/', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $line = substr_count(substr($contents, 0, $match[0][1]), "\n") + 1;
                $notices[] = 'Forms are switched off in the panel ('.$this->relative($path).':'.$line.', ->forms(false)): the form builder and the enquiries inbox are hidden. Switch them on with ->forms() before converting.';
            }
        }

        return $notices;
    }

    /**
     * The `<form>`s in one template, with the package form each posts to
     * (when it does) and the fields found in it.
     *
     * @return list<array{name: string|null, line: int, fields: array<string, array<string, mixed>>}>
     */
    public function formsIn(string $source): array
    {
        preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $source, $matches, PREG_OFFSET_CAPTURE);
        $forms = [];

        foreach ($matches[0] as [$markup, $offset]) {
            $forms[] = [
                'name' => $this->packageFormName($markup),
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'fields' => $this->fieldsIn($markup),
                'action' => $this->actionOf($markup),
                'submit' => $this->submitOf($markup),
            ];
        }

        return $forms;
    }

    /**
     * Where a form posts, when it is not to the package: a named route,
     * a controller action, or an address.
     *
     * @return array{route?: string, controller?: string, url?: string}|null
     */
    public function actionOf(string $markup): ?array
    {
        if (preg_match('/<form\b([^>]*)>/is', $markup, $open) !== 1) {
            return null;
        }

        $action = $this->attributes($open[1])['action'] ?? null;

        if (! is_string($action) || $action === '' || $this->packageFormName($markup) !== null) {
            return null;
        }

        if (preg_match('/route\(\s*[\'"]([^\'"]+)[\'"]/', $action, $match) === 1) {
            return ['route' => $match[1]];
        }

        if (preg_match('/action\(\s*\[\s*\\?([\w\\]+)::class\s*,\s*[\'"](\w+)[\'"]/', $action, $match) === 1) {
            return ['controller' => $match[1].'@'.$match[2]];
        }

        if (preg_match('/action\(\s*[\'"]([\w\\]+@\w+)[\'"]/', $action, $match) === 1) {
            return ['controller' => $match[1]];
        }

        if (preg_match('/url\(\s*[\'"]([^\'"]+)[\'"]/', $action, $match) === 1) {
            return ['url' => '/'.ltrim($match[1], '/')];
        }

        return str_contains($action, '{{') ? null : ['url' => '/'.ltrim((string) parse_url($action, PHP_URL_PATH), '/')];
    }

    /**
     * The send button's words, or the lang key they come from.
     *
     * @return array{text?: string, key?: string}|null
     */
    private function submitOf(string $markup): ?array
    {
        preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $markup, $buttons, PREG_SET_ORDER);

        foreach ($buttons as $button) {
            $type = strtolower($this->attributes($button[1])['type'] ?? 'submit');

            if ($type !== 'submit') {
                continue;
            }

            $inner = trim((string) preg_replace('/<[^>]*>/s', ' ', $button[2]));
            $key = LangFiles::keyIn($inner);
            $text = trim(html_entity_decode(strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@\w+(\(.*?\))?/s', '', $inner))));

            return array_filter(['text' => $key === null && $text !== '' ? $text : null, 'key' => $key]) ?: null;
        }

        return null;
    }

    /**
     * What the markup says about each field: its kind, its label, whether
     * it must be answered, and its choices.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsIn(string $markup): array
    {
        $labels = $this->labels($markup);
        $labelKeys = $this->labels($markup, keys: true);
        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');
        $fields = [];

        preg_match_all('/<select\b([^>]*)>(.*?)<\/select>|<(input|textarea)\b([^>]*)>/is', $markup, $found, PREG_SET_ORDER);

        foreach ($found as $one) {
            /* As [whole, element, attributes, the options of a select]. */
            $tag = ($one[3] ?? '') !== ''
                ? [$one[0], strtolower($one[3]), $one[4] ?? '']
                : [$one[0], 'select', $one[1], $one[2]];

            $attributes = $this->attributes($tag[2]);
            $rawName = $attributes['name'] ?? null;

            if (! is_string($rawName) || $rawName === '' || str_contains($rawName, '{{')) {
                continue;
            }

            $name = (string) preg_replace('/\[\]$/', '', $rawName);
            $type = strtolower((string) ($attributes['type'] ?? ($tag[1] === 'input' ? 'text' : $tag[1])));

            if (in_array($name, [...self::MACHINERY, $honeypot], true) || str_starts_with($name, '_attribution[') || in_array($type, ['submit', 'button', 'reset'], true)) {
                continue;
            }

            $label = $labels[$attributes['id'] ?? ''] ?? $labels['name:'.$rawName] ?? null;
            $labelKey = $labelKeys[$attributes['id'] ?? ''] ?? $labelKeys['name:'.$rawName] ?? null;
            $field = $fields[$name] ?? ['type' => null, 'label' => null, 'required' => false, 'options' => []];

            /*
             * Words written as `{{ __('contact.name') }}` are noted by their
             * key, for the converter to read from the lang files; the Blade
             * itself is never taken for a label.
             */
            $placeholderKey = LangFiles::keyIn($attributes['placeholder'] ?? null);
            $ariaKey = LangFiles::keyIn($attributes['aria-label'] ?? null);
            $placeholder = $placeholderKey === null && ! str_contains((string) ($attributes['placeholder'] ?? ''), '{{') ? ($attributes['placeholder'] ?? null) : null;
            $aria = $ariaKey === null && ! str_contains((string) ($attributes['aria-label'] ?? ''), '{{') ? ($attributes['aria-label'] ?? null) : null;

            $field['required'] = $field['required'] || array_key_exists('required', $attributes);
            $field['placeholder'] ??= $placeholder;
            $field['placeholder_key'] ??= $placeholderKey;

            if ($type !== 'radio' && $type !== 'checkbox') {
                $field['label'] ??= $label ?? ($labelKey === null ? ($placeholder ?? $aria) : null);
                $field['label_key'] ??= $labelKey ?? ($label === null ? ($placeholderKey ?? $ariaKey) : null);
            }

            $field['type'] = match (true) {
                $tag[1] === 'textarea' => 'long_text',
                $tag[1] === 'select' => array_key_exists('multiple', $attributes) ? 'multi_select' : 'select',
                $type === 'radio' => 'radio',
                $type === 'checkbox' && str_ends_with($rawName, '[]') => 'checkboxes',
                $type === 'checkbox' => 'checkbox',
                $type === 'email' => 'email',
                $type === 'tel' => 'phone',
                $type === 'number' => 'number',
                $type === 'url' => 'url',
                $type === 'date' => 'date',
                $type === 'time' => 'time',
                $type === 'datetime-local' => 'datetime',
                $type === 'file' => 'file',
                $type === 'hidden' => 'hidden',
                $type === 'range' => 'slider',
                default => $field['type'] ?? 'short_text',
            };

            if ($tag[1] === 'select' && isset($tag[3])) {
                preg_match_all('/<option\b([^>]*)>(.*?)<\/option>/is', $tag[3], $options, PREG_SET_ORDER);

                foreach ($options as $option) {
                    $value = $this->attributes($option[1])['value'] ?? trim(strip_tags($option[2]));
                    $optionKey = LangFiles::keyIn($option[2]);

                    if (is_string($value) && $value !== '' && ! str_contains($value, '{{')) {
                        $field['options'][] = array_filter([
                            'key' => $value,
                            'label' => $optionKey !== null ? Str::headline($value) : trim(html_entity_decode(strip_tags($option[2]))),
                            'label_key' => $optionKey,
                        ]);
                    }
                }
            }

            if ($type === 'radio' || ($type === 'checkbox' && str_ends_with($rawName, '[]'))) {
                $value = (string) ($attributes['value'] ?? '');

                if ($value !== '' && ! str_contains($value, '{{')) {
                    $field['options'][] = array_filter(['key' => $value, 'label' => $label ?? Str::headline($value), 'label_key' => $labelKey]);
                }
            }

            if ($field['type'] === 'checkbox') {
                $field['label'] ??= $label;
                $field['label_key'] ??= $labelKey;
            }

            $fields[$name] = array_filter($field, fn ($value): bool => $value !== null && $value !== []) + ['required' => false];
        }

        return $fields;
    }

    private function packageFormName(string $markup): ?string
    {
        foreach ([
            '/route\(\s*[\'"]gadya-cms\.forms\.store[\'"]\s*,\s*[\'"]([a-z0-9-]+)[\'"]/',
            '/@cmsForm\(\s*[\'"]([a-z0-9-]+)[\'"]/',
            '/\/cms\/forms\/([a-z0-9-]+)/',
        ] as $pattern) {
            if (preg_match($pattern, $markup, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * Labels by the id of what they label, and for a field wrapped inside
     * its own label.
     *
     * @return array<string, string>
     */
    private function labels(string $markup, bool $keys = false): array
    {
        $labels = [];

        preg_match_all('/<label\b([^>]*)>(.*?)<\/label>/is', $markup, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $text = $keys
                ? (string) LangFiles::keyIn(trim((string) preg_replace('/<[^>]*>/s', ' ', $match[2])))
                : trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@\w+(\(.*?\))?/s', '', $match[2])))));
            $for = $this->attributes($match[1])['for'] ?? null;

            if ($text === '') {
                continue;
            }

            if (is_string($for) && $for !== '') {
                $labels[$for] = $text;
            }

            if (preg_match('/<(?:input|select|textarea)\b[^>]*\bid=["\']([^"\']+)["\']/i', $match[2], $inner) === 1) {
                $labels[$inner[1]] = $text;
            }

            if (preg_match('/<(?:input|select|textarea)\b[^>]*\bname=["\']([^"\']+)["\']/i', $match[2], $inner) === 1) {
                $labels['name:'.$inner[1]] = $text;
            }
        }

        return $labels;
    }

    /**
     * @return array<string, string>
     */
    private function attributes(string $source): array
    {
        $attributes = [];

        preg_match_all('/([a-zA-Z_:@][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] = html_entity_decode($match[2] ?? '') ?: ($match[3] ?? '') ?: ($match[4] ?? '');
        }

        return $attributes;
    }

    /**
     * `'field' => ['required', ...]` inside a Livewire component's rules.
     *
     * @return array<string, array<string, mixed>>
     */
    private function livewireRules(string $contents): array
    {
        $fields = [];

        preg_match_all('/[\'"]([a-z_][a-z0-9_]*)[\'"]\s*=>\s*(\[[^\]]*\]|[\'"][a-z|:0-9,]+[\'"])/i', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (preg_match('/required|nullable|string|email|max:|min:|numeric|accepted|boolean|date|url/', $match[2]) !== 1) {
                continue;
            }

            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[2], $rules);
            $fields[$match[1]] = ['rules' => str_starts_with($match[2], '[') ? $rules[1] : explode('|', trim($match[2], '\'"'))];
        }

        return $fields;
    }

    private function configLine(string $name): int
    {
        $path = config_path('gadya-cms.php');

        if (! $this->files->exists($path)) {
            return 0;
        }

        foreach (explode("\n", (string) $this->files->get($path)) as $number => $line) {
            if (preg_match('/^\s*[\'"]'.preg_quote($name, '/').'[\'"]\s*=>\s*\[/', $line) === 1) {
                return $number + 1;
            }
        }

        return 0;
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(string $root): array
    {
        if (! $this->files->isDirectory($root)) {
            return [];
        }

        return collect($this->files->allFiles($root))
            ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.blade.php') && ! str_contains($file->getPathname(), '/vendor/'))
            ->map(fn ($file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (! $this->files->isDirectory($root)) {
            return [];
        }

        return collect($this->files->allFiles($root))
            ->filter(fn ($file): bool => $file->getExtension() === 'php')
            ->map(fn ($file): string => $file->getPathname())
            ->sort()
            ->values()
            ->all();
    }

    private function relative(string $path): string
    {
        return Str::startsWith($path, base_path().'/') ? Str::after($path, base_path().'/') : $path;
    }
}
