<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\CallbackForm;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Forms\FormLocale;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

/**
 * Turns a form a developer configured - and, optionally, what was found
 * in its template - into a builder form the client can change herself.
 *
 * The slug and every field's name stay exactly as they were, so the
 * enquiries already in the inbox, the people told about new ones, the
 * thank-you email and the analytics all carry on under the same names.
 * The new form is a draft: the configured one keeps answering until the
 * built one is published, and the configuration is never touched.
 */
class ConvertConfigForm
{
    public function __construct(private readonly Options $options) {}

    /**
     * Configured forms, and whether each has been built already.
     *
     * @return array<string, array{label: string, converted: bool}>
     */
    public function candidates(): array
    {
        $names = array_keys(FormDefinition::configLabels());

        if (! in_array(CallbackForm::NAME, $names, true)) {
            $names[] = CallbackForm::NAME;
        }

        $built = Form::query()->forCurrentSite()->whereIn('slug', $names)->pluck('slug')->all();
        $candidates = [];

        foreach ($names as $name) {
            $definition = FormDefinition::find($name);

            if ($definition !== null) {
                $candidates[$name] = ['label' => $definition->label, 'converted' => in_array($name, $built, true)];
            }
        }

        return $candidates;
    }

    /**
     * What the builder form would hold, without saving anything.
     *
     * @param  array<string, array<string, mixed>>  $hints  What the template says about each field: type, label, options, required
     * @param  array{submit?: array{text?: string, key?: string}|null, success?: array{text?: string, key?: string}|null}  $words  The send button's and the thank-you's words, or their lang keys
     * @return array{slug: string, title: string, fields: list<array<string, mixed>>, messages: array<string, string>, settings: array<string, mixed>, warnings: list<string>, translations: array<string, array<string, mixed>>}
     */
    public function plan(string $name, array $hints = [], array $words = [], bool $withConfig = true): array
    {
        /*
         * A form that posted to the site's own controller owes nothing to a
         * configured form that happens to share its name.
         */
        $definition = $withConfig ? FormDefinition::find($name) : null;

        if ($definition === null && $hints === []) {
            throw new InvalidArgumentException("There is no configured form called [{$name}].");
        }

        $rules = $definition?->rules ?? [];

        /* Fields the template has and the configuration does not are never kept, so they are not built either. */
        $warnings = [];

        foreach (array_diff(array_keys($hints), array_keys($rules)) as $extra) {
            if ($definition !== null) {
                $warnings[] = "The template asks for \"{$extra}\", which the configuration does not keep; it is left out. Add it in the builder if it is wanted.";
                unset($hints[$extra]);
            }
        }

        $keys = $definition !== null ? array_keys($rules) : array_keys($hints);
        $fields = [];

        foreach ($keys as $key) {
            $fields[] = $this->field((string) $key, $this->ruleList($rules[$key] ?? []), $hints[$key] ?? [], $name);
        }

        $settings = [
            'notify' => $definition?->notify ?? [],
            'analytics_event' => $definition?->analyticsEvent,
            'callback' => $name === CallbackForm::NAME,
        ];

        $reply = $this->storedReply($name);

        if ($reply !== null) {
            $settings['autoreply'] = $reply;
        }

        $plan = [
            'slug' => Str::slug($name) ?: $name,
            'title' => $definition?->label ?? Str::headline($name),
            'fields' => FormSchema::normalise($fields),
            'messages' => array_filter([
                'success' => $definition?->success ?? ($words['success']['text'] ?? null),
                'submit' => $words['submit']['text'] ?? null,
            ]),
            'settings' => $settings,
            'warnings' => $warnings,
            'translations' => [],
        ];

        return $this->withLangFiles($plan, $hints, $words);
    }

    /**
     * @param  array<string, array<string, mixed>>  $hints
     * @param  array{submit?: array{text?: string, key?: string}|null, success?: array{text?: string, key?: string}|null}  $words
     * @param  array<string, mixed>  $settings  Settings of the new form's own, over the ones planned: its destinations, say
     */
    public function convert(string $name, array $hints = [], array $words = [], array $settings = [], bool $withConfig = true): Form
    {
        $plan = $this->plan($name, $hints, $words, $withConfig);

        if (Form::query()->forCurrentSite()->where('slug', $plan['slug'])->exists()) {
            throw new InvalidArgumentException("There is already a builder form at [{$plan['slug']}].");
        }

        $form = Form::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'slug' => $plan['slug'],
            'title' => $plan['title'],
            'status' => Form::STATUS_DRAFT,
            'template' => 'config:'.$name,
            'fields' => $plan['fields'],
            'messages' => $plan['messages'],
            'settings' => [...$plan['settings'], ...$settings],
            'created_by' => auth()->id(),
        ]);

        /*
         * The site's own translations, from its lang files, go live with
         * the form: they are words the site already shows, not a machine's
         * draft waiting for someone to read it.
         */
        foreach ($plan['translations'] as $locale => $overlay) {
            $row = app(Translations::class)->store('form:'.$form->getKey(), $locale, $overlay, machine: false);
            $row->forceFill(['published' => $overlay, 'reviewed_at' => now()])->save();
        }

        app(Translations::class)->forget();

        return $form;
    }

    /**
     * Words the template wrote as `__('contact.name')`, `@lang(...)` or
     * `trans(...)`, read from every language in the site's lang files: the
     * CMS's default language becomes the form's own words, and each other
     * language an overlay, as a translation made in the panel would be.
     *
     * Words written out in the template ("Name *") that are, word for
     * word, a string in the lang files are given that string's
     * translations too, and noted under `lang_matches` for a person to
     * check. A label mixing keys and words - a consent sentence with a
     * link - is put together in each language. A required mark (`*`,
     * `(required)`) is taken off every language's words, and makes the
     * question required.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $hints
     * @param  array<string, mixed>  $words
     * @return array<string, mixed>
     */
    public function withLangFiles(array $plan, array $hints, array $words = []): array
    {
        $lang = app(LangFiles::class);
        $default = app(Locales::class)->default();
        $locales = array_values(array_unique(array_map(fn (string $locale): string => FormLocale::normalise($locale) ?? $locale, $lang->locales())));
        $overlays = [];
        $missing = [];
        $matches = [];
        $plan['lang_matches'] ??= [];

        $read = function (string $key) use ($lang, $default, &$missing): array {
            $everywhere = $lang->everywhere($key);
            $dotted = preg_match('/^[\w-]+(\.[\w-]+)+$/', $key) === 1;

            if ($everywhere === [] && $dotted) {
                $missing[] = $key;
            }

            $required = false;

            foreach ($everywhere as $locale => $text) {
                [$everywhere[$locale], $found] = LabelText::strip($text);
                $required = $required || ($found && $locale === $default);
            }

            /*
             * A JSON-style key (`__('Building work')`) is itself the words
             * in the language it was written in.
             */
            $own = $everywhere[$default] ?? ($dotted ? (reset($everywhere) ?: null) : LabelText::strip($key)[0]);

            return ['own' => $own, 'others' => array_diff_key($everywhere, [$default => true]), 'required' => $required];
        };

        /* Words written out, matched to the lang files' strings: the group most of the form's words share wins. */
        $texts = [];

        foreach ($hints as $hint) {
            if (! isset($hint['label_key']) && ! isset($hint['label_parts']) && is_string($hint['label'] ?? null)) {
                $texts[] = $hint['label'];
            }

            foreach ((array) ($hint['label_parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $texts[] = $part['text'];
                }
            }

            foreach ((array) ($hint['options'] ?? []) as $option) {
                if (is_array($option) && ! isset($option['label_key']) && ! isset($option['labels']) && is_string($option['label'] ?? null)) {
                    $texts[] = $option['label'];
                }
            }
        }

        foreach (['submit', 'success'] as $message) {
            if (! isset($words[$message]['key']) && is_string($words[$message]['text'] ?? null)) {
                $texts[] = $words[$message]['text'];
            }
        }

        $candidates = [];
        $groups = [];

        foreach (array_unique($texts) as $text) {
            $candidates[$text] = $lang->keysFor($text);

            foreach (array_unique(array_map(fn (string $key): string => Str::beforeLast($key, '.'), $candidates[$text])) as $group) {
                $groups[$group] = ($groups[$group] ?? 0) + 1;
            }
        }

        $keyFor = function (string $text) use (&$candidates, $groups, &$matches): ?string {
            $keys = $candidates[$text] ?? [];

            if ($keys === []) {
                return null;
            }

            usort($keys, fn (string $a, string $b): int => ($groups[Str::beforeLast($b, '.')] ?? 0) <=> ($groups[Str::beforeLast($a, '.')] ?? 0));
            $matches[$text] = $keys[0];

            return $keys[0];
        };

        /* A label of parts, put together in one language. */
        $compose = function (array $parts, string $locale) use ($lang, $default, $keyFor, &$missing): array {
            $pieces = [];
            $translated = false;

            foreach ($parts as $part) {
                $key = $part['key'] ?? (isset($part['text']) ? $keyFor($part['text']) : null);

                if ($key === null) {
                    $pieces[] = (string) ($part['text'] ?? '');

                    continue;
                }

                $text = $lang->get($key, $locale);
                $translated = $translated || ($text !== null && $locale !== $default);
                $text ??= $lang->get($key, $default) ?? ($part['text'] ?? (preg_match('/^[\w-]+(\.[\w-]+)+$/', $key) === 1 ? null : $key));

                if ($text === null) {
                    $missing[] = $key;
                }

                $pieces[] = (string) $text;
            }

            [$words, $required] = LabelText::strip(LabelText::join($pieces));

            return ['words' => $words, 'required' => $required, 'translated' => $translated];
        };

        foreach ($plan['fields'] as $index => $field) {
            $hint = $hints[$field['key']] ?? [];

            /* A label written out, matched to the lang files. */
            if (! isset($hint['label_key']) && ! isset($hint['label_parts']) && is_string($hint['label'] ?? null)) {
                $matched = $keyFor($hint['label']);

                if ($matched !== null) {
                    $hint['label_key'] = $matched;
                }
            }

            if (is_array($hint['label_parts'] ?? null) && $hint['label_parts'] !== []) {
                $own = $compose($hint['label_parts'], $default);

                if ($own['words'] !== '') {
                    $plan['fields'][$index]['label'] = $own['words'];
                }

                $plan['fields'][$index]['required'] = $plan['fields'][$index]['required'] || $own['required'];

                foreach ($locales as $locale) {
                    if ($locale === $default) {
                        continue;
                    }

                    $other = $compose($hint['label_parts'], $locale);

                    if ($other['translated'] && $other['words'] !== $own['words']) {
                        $overlays[$locale]['fields'][$field['key']]['key'] = $field['key'];
                        $overlays[$locale]['fields'][$field['key']]['label'] = $other['words'];
                    }
                }
            }

            foreach (['label' => 'label_key', 'placeholder' => 'placeholder_key'] as $part => $hintKey) {
                if (! is_string($hint[$hintKey] ?? null) || ($part === 'label' && isset($hint['label_parts']))) {
                    continue;
                }

                $found = $read($hint[$hintKey]);

                if ($found['own'] !== null) {
                    $plan['fields'][$index][$part] = $found['own'];
                }

                if ($part === 'label' && $found['required']) {
                    $plan['fields'][$index]['required'] = true;
                }

                foreach ($found['others'] as $locale => $text) {
                    $overlays[$locale]['fields'][$field['key']]['key'] = $field['key'];
                    $overlays[$locale]['fields'][$field['key']][$part] = $text;
                }
            }

            foreach (array_values((array) ($hint['options'] ?? [])) as $position => $option) {
                if (! is_array($option)) {
                    continue;
                }

                /* The plan's choices have their keys tidied ("deep-tissue" becomes "deep_tissue"). */
                $tidy = fn (string $key): string => Str::of($key)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
                $targetIndex = collect($plan['fields'][$index]['options'] ?? [])->search(fn (array $one): bool => $tidy((string) $one['key']) === $tidy((string) ($option['key'] ?? '')));
                $targetIndex = $targetIndex === false ? (isset($plan['fields'][$index]['options'][$position]) && ! isset($option['key']) ? $position : null) : $targetIndex;

                if ($targetIndex === null) {
                    continue;
                }

                $target = $plan['fields'][$index]['options'][$targetIndex];
                $key = $option['label_key'] ?? (! isset($option['labels']) && is_string($option['label'] ?? null) ? $keyFor($option['label']) : null);

                /* Choices a loop wrote out in each language. */
                if (is_array($option['labels'] ?? null)) {
                    foreach ($option['labels'] as $locale => $text) {
                        if ($locale === $default) {
                            $plan['fields'][$index]['options'][$targetIndex]['label'] = (string) $text;
                        } else {
                            $overlays[$locale]['fields'][$field['key']]['key'] = $field['key'];
                            $overlays[$locale]['fields'][$field['key']]['options'][$target['key']] = ['key' => $target['key'], 'label' => (string) $text];
                        }
                    }

                    continue;
                }

                if (! is_string($key)) {
                    continue;
                }

                $found = $read($key);

                if ($found['own'] !== null) {
                    $plan['fields'][$index]['options'][$targetIndex]['label'] = $found['own'];
                }

                foreach ($found['others'] as $locale => $text) {
                    $overlays[$locale]['fields'][$field['key']]['key'] = $field['key'];
                    $overlays[$locale]['fields'][$field['key']]['options'][$target['key']] = ['key' => $target['key'], 'label' => $text];
                }
            }
        }

        foreach (['submit', 'success'] as $message) {
            $key = $words[$message]['key'] ?? (is_string($words[$message]['text'] ?? null) ? $keyFor($words[$message]['text']) : null);

            if (! is_string($key)) {
                continue;
            }

            $found = $read($key);

            if ($found['own'] !== null) {
                $plan['messages'][$message] = $found['own'];
            }

            foreach ($found['others'] as $locale => $text) {
                $overlays[$locale]['messages'][$message] = $text;
            }
        }

        /* A language's translation of a choice list is the whole list, in the form's order. */
        foreach ($overlays as $locale => $overlay) {
            foreach ($overlay['fields'] ?? [] as $key => $field) {
                if (! isset($field['options'])) {
                    continue;
                }

                $own = collect($plan['fields'])->firstWhere('key', $key);
                $options = [];

                foreach ((array) ($own['options'] ?? []) as $option) {
                    $options[] = $field['options'][$option['key']] ?? ['key' => $option['key'], 'label' => $option['label']];
                }

                $overlays[$locale]['fields'][$key]['options'] = $options;
            }

            if (isset($overlays[$locale]['fields'])) {
                $overlays[$locale]['fields'] = array_values($overlays[$locale]['fields']);
            }
        }

        foreach (array_unique($missing) as $key) {
            $plan['warnings'][] = "The lang key \"{$key}\" is not in any of the site's lang files; its words were not imported.";
        }

        foreach ($matches as $text => $key) {
            $plan['lang_matches'][] = ['text' => $text, 'key' => $key];
        }

        $plan['translations'] = $overlays;

        return $plan;
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, mixed>  $hint
     * @return array<string, mixed>
     */
    public function field(string $key, array $rules, array $hint = [], string $form = ''): array
    {
        $has = fn (string $rule): bool => collect($rules)->contains(fn (string $one): bool => $one === $rule || str_starts_with($one, $rule.':'));
        $value = fn (string $rule): ?string => collect($rules)->first(fn (string $one): bool => str_starts_with($one, $rule.':')) === null
            ? null
            : Str::after((string) collect($rules)->first(fn (string $one): bool => str_starts_with($one, $rule.':')), ':');
        $in = $value('in');
        $options = $in !== null
            ? array_map(fn (string $option): array => ['key' => $option, 'label' => (string) ($hint['option_labels'][$option] ?? Str::headline($option))], array_map(fn (string $option): string => trim($option, '"\''), str_getcsv($in)))
            : array_map(fn ($option): array => is_array($option) ? ['key' => (string) ($option['key'] ?? ''), 'label' => (string) ($option['label'] ?? $option['key'] ?? '')] : ['key' => (string) $option, 'label' => (string) $option], (array) ($hint['options'] ?? []));
        $max = $value('max');
        $hinted = is_string($hint['type'] ?? null) ? $hint['type'] : null;

        $type = match (true) {
            $has('accepted') => $form === CallbackForm::NAME || preg_match('/consent|agree|terms|permission/', $key) === 1 ? 'consent' : 'checkbox',
            $hinted === 'checkbox' && preg_match('/consent|agree|terms|privacy|gdpr/', $key) === 1 => 'consent',
            $has('email') || $hinted === 'email' => 'email',
            $has('url') || $hinted === 'url' => 'url',
            $has('image') => 'image',
            $has('file') || $has('mimes') || $has('extensions') || $hinted === 'file' => 'file',
            in_array($hinted, ['time', 'datetime', 'slider'], true) => $hinted,
            $has('date') || $has('date_format') || $hinted === 'date' => 'date',
            $has('boolean') || $hinted === 'checkbox' => 'checkbox',
            $options !== [] && ($has('array') || $hinted === 'checkboxes') => 'checkboxes',
            $options !== [] => in_array($hinted, ['radio', 'multi_select'], true) ? $hinted : 'select',
            /* Choices the template draws from something that could not be worked out: the right kind, for a person to fill in. */
            in_array($hinted, ['select', 'multi_select', 'radio', 'checkboxes'], true) => $hinted,
            $has('numeric') || $has('integer') || $hinted === 'number' => 'number',
            preg_match('/phone|tel|mobile|cell/', $key) === 1 || $hinted === 'phone' => 'phone',
            $hinted === 'long_text' || ($max !== null && (int) $max > 255) || preg_match('/message|comment|details|enquiry|inquiry|notes|question/', $key) === 1 => 'long_text',
            $hinted === 'hidden' => 'hidden',
            default => 'short_text',
        };

        $fieldRules = [];

        if (in_array($type, ['short_text', 'long_text'], true) && $max !== null && is_numeric($max)) {
            $fieldRules['max_length'] = (int) $max;
        }

        if (in_array($type, ['short_text', 'long_text'], true) && is_numeric($value('min'))) {
            $fieldRules['min_length'] = (int) $value('min');
        }

        if ($type === 'number') {
            foreach (['min', 'max'] as $bound) {
                if (is_numeric($value($bound))) {
                    $fieldRules[$bound] = $value($bound) + 0;
                }
            }
        }

        if ($type === 'consent' && $form === CallbackForm::NAME) {
            $fieldRules['callback'] = true;
        }

        $label = trim((string) ($hint['label'] ?? ''));

        return array_filter([
            'type' => $type,
            'key' => $key,
            'label' => $type === 'consent' && $form === CallbackForm::NAME ? CallbackForm::consentText() : ($label !== '' ? $label : Str::headline($key)),
            'required' => $has('required') || $has('accepted') || (bool) ($hint['required'] ?? false),
            'placeholder' => (string) ($hint['placeholder'] ?? ''),
            'options' => $options,
            'rules' => $fieldRules,
        ], fn ($part): bool => $part !== [] && $part !== '');
    }

    /**
     * @return list<string>
     */
    private function ruleList(mixed $rules): array
    {
        $list = is_string($rules) ? explode('|', $rules) : (array) $rules;

        return array_values(array_filter(array_map(
            fn ($rule): ?string => is_string($rule) ? trim($rule) : ($rule instanceof Stringable || (is_object($rule) && method_exists($rule, '__toString')) ? (string) $rule : null),
            $list,
        )));
    }

    /**
     * The thank-you email the client wrote for this form, in the builder's
     * `{field}` style.
     *
     * @return array{enabled: bool, subject: string, body: string}|null
     */
    private function storedReply(string $name): ?array
    {
        $stored = (array) $this->options->get('forms.replies', []);
        $reply = $stored[$name] ?? null;

        if (! is_array($reply)) {
            return null;
        }

        $tags = fn (string $text): string => (string) preg_replace('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', '{$1}', $text);

        return [
            'enabled' => (bool) ($reply['enabled'] ?? false),
            'subject' => $tags((string) ($reply['subject'] ?? '')),
            'body' => $tags((string) ($reply['body'] ?? '')),
        ];
    }
}
