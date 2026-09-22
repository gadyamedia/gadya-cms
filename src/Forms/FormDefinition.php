<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Options\Options;
use Illuminate\Support\Str;

/**
 * A form the public site may post to, as configured. Only fields listed
 * here are kept, and only under the rules given, so a form cannot be
 * used to store anything the site did not ask for.
 */
final class FormDefinition
{
    /**
     * @param  array<string, string|list<string>>  $rules
     * @param  list<string>  $notify
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly array $rules,
        public readonly array $notify,
        public readonly string $success,
        public readonly ?string $analyticsEvent,
    ) {}

    public static function find(string $name): ?self
    {
        $forms = (array) config('gadya-cms.forms.forms', []);

        if (! isset($forms[$name]) || ! is_array($forms[$name])) {
            return null;
        }

        $form = $forms[$name];

        return new self(
            name: $name,
            label: (string) ($form['label'] ?? Str::headline($name)),
            rules: (array) ($form['fields'] ?? []),
            notify: self::recipients($name, (array) ($form['notify'] ?? [])),
            success: (string) ($form['success'] ?? 'Thank you. We will be in touch soon.'),
            analyticsEvent: array_key_exists('analytics_event', $form) ? $form['analytics_event'] : 'lead_form_submit',
        );
    }

    /**
     * Who is told about a new enquiry: the addresses written in config,
     * and the ones the client added in the panel - so a new member of
     * staff can be added without a developer or a deploy.
     *
     * @param  array<int, mixed>  $configured
     * @return list<string>
     */
    public static function recipients(string $name, array $configured = []): array
    {
        $chosen = (array) rescue(fn (): mixed => app(Options::class)->get('forms.notify.'.$name, []), [], report: false);

        return collect([...$configured, ...$chosen])
            ->filter(fn ($address): bool => is_string($address) && filter_var(trim($address), FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $address): string => strtolower(trim($address)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The addresses the client added in the panel, without the ones that
     * are fixed in config.
     *
     * @return list<string>
     */
    public static function chosenRecipients(string $name): array
    {
        return array_values(array_filter(
            (array) rescue(fn (): mixed => app(Options::class)->get('forms.notify.'.$name, []), [], report: false),
            'is_string',
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach ((array) config('gadya-cms.forms.forms', []) as $name => $form) {
            $labels[(string) $name] = (string) (is_array($form) ? ($form['label'] ?? Str::headline((string) $name)) : $name);
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->rules);
    }
}
