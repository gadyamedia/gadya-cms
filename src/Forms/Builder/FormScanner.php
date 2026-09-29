<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\FormLocale;
use Gadya\Cms\Localisation\Locales;
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
            foreach ($this->formsIn((string) $this->files->get($path), $path, $app) as $form) {
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
                    'analytics' => $form['analytics'],
                    'unmapped' => $form['unmapped'],
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
        $analysis = $reader->read($resolved['file'], $resolved['class'], $resolved['method'], $fields, $resolved['route'] ?? ($action['url'] ?? null));
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
     * (when it does), the fields found in it, and where it posts.
     *
     * Choices drawn in a `@foreach` are written out in every language the
     * site has, when what they loop over can be worked out; the path of
     * the template lets the page's controller be read for that.
     *
     * @return list<array{name: string|null, line: int, fields: array<string, array<string, mixed>>, action: array<string, string>|null, submit: array{text?: string, key?: string}|null, analytics: array{event: string, source: string}|null, unmapped: list<array{field: string, note: string}>}>
     */
    public function formsIn(string $source, ?string $path = null, ?string $app = null): array
    {
        preg_match_all('/<form\b[^>]*>.*?<\/form>/is', $source, $matches, PREG_OFFSET_CAPTURE);
        $forms = [];

        foreach ($matches[0] as [$markup, $offset]) {
            [$fields, $unmapped] = $this->fieldsWithLoops($markup, $source, $path, $app);

            $forms[] = [
                'name' => $this->packageFormName($markup),
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'fields' => $fields,
                'action' => $this->actionOf($markup),
                'submit' => $this->submitOf($markup),
                'analytics' => $this->analyticsOf($markup, $source),
                'unmapped' => $unmapped,
            ];
        }

        return $forms;
    }

    /**
     * The fields of one form, with the choices its loops draw in every
     * language, and a note for each loop that could not be worked out.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<array{field: string, note: string}>}
     */
    private function fieldsWithLoops(string $markup, string $source, ?string $path, ?string $app): array
    {
        $app ??= rescue(fn (): string => app_path(), null, report: false);
        $locales = rescue(fn (): array => app(LangFiles::class)->locales(), [], report: false);
        $default = rescue(fn (): string => app(Locales::class)->default(), 'en', report: false);
        $locales = $locales === [] ? [$default] : $locales;
        $directories = $app === null ? [] : [$app, dirname($app).'/routes'];

        $loops = app(BladeLoops::class)->expand($markup, $source, $path, $locales, $directories);
        $expanded = $loops['markup'];
        $first = (string) array_key_first($expanded);
        $fields = $this->fieldsIn($expanded[$first]);
        $unmapped = [];

        /* Each other language's words for choices the loops wrote out as plain text. */
        foreach (array_slice($expanded, 1, null, true) as $locale => $other) {
            foreach ($this->fieldsIn($other) as $name => $field) {
                foreach ($field['options'] ?? [] as $option) {
                    foreach ($fields[$name]['options'] ?? [] as $index => $own) {
                        if ($own['key'] === $option['key'] && ! isset($own['label_key']) && isset($option['label']) && $option['label'] !== $own['label']) {
                            $fields[$name]['options'][$index]['labels'][FormLocale::normalise($locale) ?? $locale] = $option['label'];
                        }
                    }
                }
            }
        }

        foreach ($fields as $name => $field) {
            foreach ($field['options'] ?? [] as $index => $option) {
                if (isset($option['labels'])) {
                    $fields[$name]['options'][$index]['labels'] = [FormLocale::normalise($first) ?? $first => $option['label'], ...$option['labels']];
                }
            }
        }

        $file = $path === null ? 'the template' : $this->relative($path);

        foreach ($loops['unresolved'] as $loop) {
            foreach ($loop['fields'] as $name) {
                if (! isset($fields[$name])) {
                    continue;
                }

                $fields[$name]['options_from'] = $loop['expression'];
                $unmapped[] = [
                    'field' => $name,
                    'note' => 'options come from '.$loop['expression'].' in '.$file.'; fill them in'.(($fields[$name]['options'] ?? []) === [] ? '' : ' (the choices written out in the template are kept)').'.',
                ];
            }
        }

        return [$fields, $unmapped];
    }

    /**
     * Where a form posts, when it is not to the package: a named route
     * (`route()`, or a helper of the site's own such as `locale_route()`),
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

        if (preg_match('/(?<![\w>:])(?:\w*_)?route\(\s*[\'"]([^\'"]+)[\'"]/i', $action, $match) === 1
            || preg_match('/(?:::|->)(?:\w*R|r)oute\(\s*[\'"]([^\'"]+)[\'"]/', $action, $match) === 1) {
            return ['route' => $match[1]];
        }

        if (preg_match('/action\(\s*\[\s*\\\\?([\w\\\\]+)::class\s*,\s*[\'"](\w+)[\'"]/', $action, $match) === 1) {
            return ['controller' => $match[1].'@'.$match[2]];
        }

        /* An invokable controller: action(ContactController::class) or action([ContactController::class]). */
        if (preg_match('/action\(\s*\[?\s*\\\\?([\w\\\\]+)::class\s*\]?\s*[,)]/', $action, $match) === 1) {
            return ['controller' => $match[1].'@__invoke'];
        }

        if (preg_match('/action\(\s*[\'"]([\w\\\\]+@\w+)[\'"]/', $action, $match) === 1) {
            return ['controller' => $match[1]];
        }

        if (preg_match('/action\(\s*[\'"]([\w\\\\]+)[\'"]/', $action, $match) === 1) {
            return ['controller' => $match[1].'@__invoke'];
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
        preg_match_all('/<button\b([^>]*)>(.*?)<\/button>|<input\b([^>]*\btype\s*=\s*["\']submit["\'][^>]*)>/is', $markup, $buttons, PREG_SET_ORDER);

        foreach ($buttons as $button) {
            if (($button[3] ?? '') !== '') {
                $value = $this->attributes($button[3])['value'] ?? '';
                $key = LangFiles::keyIn($value);

                return array_filter(['text' => $key === null && $value !== '' && ! str_contains($value, '{{') ? $value : null, 'key' => $key]) ?: null;
            }

            $type = strtolower($this->attributes($button[1])['type'] ?? 'submit');

            if ($type !== 'submit') {
                continue;
            }

            $words = LabelText::parse($button[2]);
            $keys = array_column($words['parts'], 'key');
            $text = LabelText::plain($words['parts']);

            return array_filter(['text' => $keys === [] ? $text : null, 'key' => count($keys) === 1 && $text === null ? $keys[0] : null]) ?: null;
        }

        return null;
    }

    /**
     * The analytics event the old form sent: from a data attribute on the
     * form or its send button, or a `gtag('event', ...)` /
     * `dataLayer.push({event: ...})` in the template's script.
     *
     * @return array{event: string, source: string}|null
     */
    public function analyticsOf(string $markup, string $template = ''): ?array
    {
        $attributes = ['data-analytics-event', 'data-event', 'data-gtm-event', 'data-ga-event', 'data-track', 'data-analytics'];
        $places = [];

        if (preg_match('/<form\b([^>]*)>/is', $markup, $form) === 1) {
            $places['the <form>'] = $this->attributes($form[1]);
        }

        preg_match_all('/<button\b([^>]*)>|<input\b([^>]*\btype\s*=\s*["\']submit["\'][^>]*)>/is', $markup, $buttons, PREG_SET_ORDER);

        foreach ($buttons as $button) {
            $found = $this->attributes(($button[2] ?? '') !== '' ? $button[2] : $button[1]);

            if (strtolower($found['type'] ?? 'submit') === 'submit') {
                $places['the send button'] = $found;

                break;
            }
        }

        foreach ($places as $place => $found) {
            foreach ($attributes as $attribute) {
                $value = trim((string) ($found[$attribute] ?? ''));

                if ($value !== '' && preg_match('/^[\w.:-]+$/', $value) === 1) {
                    return ['event' => $value, 'source' => $attribute.' on '.$place];
                }
            }
        }

        foreach ([
            '/\bgtag\(\s*[\'"]event[\'"]\s*,\s*[\'"]([\w.:-]+)[\'"]/' => 'gtag(\'event\') in the template\'s script',
            '/dataLayer\.push\(\s*\{[^}]*?[\'"]?event[\'"]?\s*:\s*[\'"]([\w.:-]+)[\'"]/s' => 'dataLayer.push() in the template\'s script',
        ] as $pattern => $source) {
            preg_match_all($pattern, $template, $events);

            foreach ($events[1] as $event) {
                if (! in_array($event, ['page_view', 'gtm.js', 'js', 'config'], true)) {
                    return ['event' => $event, 'source' => $source];
                }
            }
        }

        return null;
    }

    /**
     * What the markup says about each field: its kind, its label, whether
     * it must be answered, and its choices.
     *
     * A label is only ever the words of its `<label>` (by `for`, or
     * wrapped around the control), a group's `<legend>`, or an
     * `aria-label` - never the control's choices or the markup beside it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsIn(string $markup): array
    {
        $labels = $this->labels($markup);
        $legends = $this->legends($markup);
        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');
        $fields = [];

        preg_match_all('/<select\b('.LabelText::ATTRIBUTES.')>(.*?)<\/select>|<(input|textarea)\b('.LabelText::ATTRIBUTES.')>/is', $markup, $found, PREG_SET_ORDER);

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

            $label = $labels[$attributes['id'] ?? ''] ?? $labels['name:'.$rawName.'#'.($attributes['value'] ?? '')] ?? $labels['name:'.$rawName] ?? null;
            $field = $fields[$name] ?? ['type' => null, 'label' => null, 'required' => false, 'options' => []];
            $group = $type === 'radio' || ($type === 'checkbox' && str_ends_with($rawName, '[]'));

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

            if (! $group) {
                if ($label !== null && ($field['label'] ?? null) === null && ($field['label_key'] ?? null) === null && ! isset($field['label_parts'])) {
                    $field = [...$field, ...$this->labelHint($label)];
                }

                $field['label'] ??= ($field['label_key'] ?? null) === null && ! isset($field['label_parts']) ? ($placeholder ?? $aria) : null;
                $field['label_key'] ??= ($field['label'] ?? null) === null && ! isset($field['label_parts']) ? ($placeholderKey ?? $ariaKey) : null;
                $field['required'] = $field['required'] || ($label['required'] ?? false);
            } elseif (isset($legends[$name]) && ($field['label'] ?? null) === null && ($field['label_key'] ?? null) === null) {
                $field = [...$field, ...$this->labelHint($legends[$name])];
                $field['required'] = $field['required'] || $legends[$name]['required'];
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
                preg_match_all('/<option\b('.LabelText::ATTRIBUTES.')>(.*?)<\/option>/is', $tag[3], $options, PREG_SET_ORDER);

                foreach ($options as $option) {
                    $words = LabelText::parse($option[2]);
                    $keys = array_column($words['parts'], 'key');
                    $optionKey = count($keys) === 1 && LabelText::plain($words['parts']) === null ? $keys[0] : null;
                    $text = LabelText::plain($words['parts']);
                    $value = $this->attributes($option[1])['value'] ?? $text;

                    /* The empty first choice ("Choose one", "Not sure yet") is the dropdown's placeholder. */
                    if ($value === '' || $value === null) {
                        $field['placeholder'] ??= $optionKey === null ? $text : null;
                        $field['placeholder_key'] ??= $optionKey;

                        continue;
                    }

                    if (is_string($value) && ! str_contains($value, '{{') && ! str_contains($value, '{!!')) {
                        $field['options'][] = array_filter([
                            'key' => $value,
                            'label' => $optionKey !== null || $text === null ? Str::headline($value) : $text,
                            'label_key' => $optionKey,
                        ]);
                    }
                }
            }

            if ($group) {
                $value = (string) ($attributes['value'] ?? '');

                if ($value !== '' && ! str_contains($value, '{{')) {
                    $hint = $label === null ? [] : $this->labelHint($label);
                    $field['options'][] = array_filter([
                        'key' => $value,
                        'label' => $hint['label'] ?? Str::headline($value),
                        'label_key' => $hint['label_key'] ?? null,
                    ]);
                }
            }

            if ($field['type'] === 'checkbox' && $label !== null && ($field['label'] ?? null) === null && ($field['label_key'] ?? null) === null && ! isset($field['label_parts'])) {
                $field = [...$field, ...$this->labelHint($label)];
                $field['required'] = $field['required'] || $label['required'];
            }

            $fields[$name] = array_filter($field, fn ($value): bool => $value !== null && $value !== []) + ['required' => false];
        }

        return $fields;
    }

    /**
     * A parsed label as a field's hint: its plain words, its one lang key,
     * or - for words mixing keys and text, such as a consent sentence with
     * a link in it - its parts, for the converter to put together in every
     * language; and the links in it.
     *
     * @param  array{parts: list<array{text?: string, key?: string}>, required: bool, links: list<string>}  $label
     * @return array<string, mixed>
     */
    private function labelHint(array $label): array
    {
        $keys = array_column($label['parts'], 'key');
        $text = LabelText::plain($label['parts']);

        return array_filter([
            'label' => $keys === [] ? $text : null,
            'label_key' => count($keys) === 1 && $text === null ? $keys[0] : null,
            'label_parts' => count($label['parts']) > 1 ? $label['parts'] : null,
            'links' => $label['links'] === [] ? null : $label['links'],
        ], fn ($value): bool => $value !== null);
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
     * Labels by the id of what they label, by the name of a control wrapped
     * inside one (and by name and value, for one choice of a group), each
     * as its words' parts: never the control, its choices or the markup
     * beside it.
     *
     * @return array<string, array{parts: list<array{text?: string, key?: string}>, required: bool, links: list<string>}>
     */
    private function labels(string $markup): array
    {
        $labels = [];

        preg_match_all('/<label\b('.LabelText::ATTRIBUTES.')>(.*?)<\/label>/is', $markup, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $label = LabelText::parse($match[2]);
            $for = $this->attributes($match[1])['for'] ?? null;

            if ($label['parts'] === []) {
                continue;
            }

            if (is_string($for) && $for !== '') {
                $labels[$for] = $label;
            }

            preg_match_all('/<(?:input|select|textarea)\b('.LabelText::ATTRIBUTES.')>/is', $match[2], $controls, PREG_SET_ORDER);

            foreach ($controls as $control) {
                $attributes = $this->attributes($control[1]);

                if (isset($attributes['id']) && $attributes['id'] !== '') {
                    $labels[$attributes['id']] ??= $label;
                }

                if (isset($attributes['name']) && $attributes['name'] !== '') {
                    $labels['name:'.$attributes['name'].'#'.($attributes['value'] ?? '')] ??= $label;
                    $labels['name:'.$attributes['name']] ??= $label;
                }
            }
        }

        return $labels;
    }

    /**
     * A `<fieldset>`'s `<legend>` (or a group's `aria-label`) by the name
     * of each control inside it: the question a group of choices asks.
     *
     * @return array<string, array{parts: list<array{text?: string, key?: string}>, required: bool, links: list<string>}>
     */
    private function legends(string $markup): array
    {
        $legends = [];

        preg_match_all('/<fieldset\b('.LabelText::ATTRIBUTES.')>(.*?)<\/fieldset>|<(?:div|ul|p)\b([^>]*\brole\s*=\s*["\'](?:radio)?group["\'][^>]*)>(.*?)<\/(?:div|ul|p)>/is', $markup, $groups, PREG_SET_ORDER);

        foreach ($groups as $group) {
            $inside = ($group[2] ?? '') !== '' ? $group[2] : ($group[4] ?? '');
            $attributes = $this->attributes(($group[1] ?? '') !== '' ? $group[1] : ($group[3] ?? ''));

            if (preg_match('/<legend\b[^>]*>(.*?)<\/legend>/is', $inside, $legend) === 1) {
                $words = LabelText::parse($legend[1]);
            } elseif (isset($attributes['aria-label'])) {
                $words = LabelText::parse($attributes['aria-label']);
            } else {
                continue;
            }

            if ($words['parts'] === []) {
                continue;
            }

            preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname\s*=\s*["\']([^"\'{]+)["\']/i', $inside, $names);

            foreach ($names[1] as $name) {
                $legends[(string) preg_replace('/\[\]$/', '', $name)] ??= $words;
            }
        }

        return $legends;
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
