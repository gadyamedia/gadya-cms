<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\CallbackForm;
use Gadya\Cms\Forms\FormDefinition;
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
     * @return array{slug: string, title: string, fields: list<array<string, mixed>>, messages: array<string, string>, settings: array<string, mixed>, warnings: list<string>}
     */
    public function plan(string $name, array $hints = []): array
    {
        $definition = FormDefinition::find($name);

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

        return [
            'slug' => Str::slug($name) ?: $name,
            'title' => $definition?->label ?? Str::headline($name),
            'fields' => FormSchema::normalise($fields),
            'messages' => array_filter(['success' => $definition?->success]),
            'settings' => $settings,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $hints
     */
    public function convert(string $name, array $hints = []): Form
    {
        $plan = $this->plan($name, $hints);

        if (Form::query()->forCurrentSite()->where('slug', $plan['slug'])->exists()) {
            throw new InvalidArgumentException("There is already a builder form at [{$plan['slug']}].");
        }

        return Form::query()->create([
            'site_id' => app(SiteContext::class)->id(),
            'slug' => $plan['slug'],
            'title' => $plan['title'],
            'status' => Form::STATUS_DRAFT,
            'template' => 'config:'.$name,
            'fields' => $plan['fields'],
            'messages' => $plan['messages'],
            'settings' => $plan['settings'],
            'created_by' => auth()->id(),
        ]);
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
            : array_map(fn ($option): array => is_array($option) ? $option : ['key' => (string) $option, 'label' => (string) $option], (array) ($hint['options'] ?? []));
        $max = $value('max');
        $hinted = is_string($hint['type'] ?? null) ? $hint['type'] : null;

        $type = match (true) {
            $has('accepted') => $form === CallbackForm::NAME || preg_match('/consent|agree|terms|permission/', $key) === 1 ? 'consent' : 'checkbox',
            $has('email') || $hinted === 'email' => 'email',
            $has('url') || $hinted === 'url' => 'url',
            $has('image') => 'image',
            $has('file') || $has('mimes') || $has('extensions') || $hinted === 'file' => 'file',
            in_array($hinted, ['time', 'datetime', 'slider'], true) => $hinted,
            $has('date') || $has('date_format') || $hinted === 'date' => 'date',
            $has('boolean') || $hinted === 'checkbox' => 'checkbox',
            $options !== [] && ($has('array') || $hinted === 'checkboxes') => 'checkboxes',
            $options !== [] => in_array($hinted, ['radio', 'multi_select'], true) ? $hinted : 'select',
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
