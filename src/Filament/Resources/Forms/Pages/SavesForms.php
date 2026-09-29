<?php

namespace Gadya\Cms\Filament\Resources\Forms\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Gadya\Cms\Filament\Resources\Forms\FormBuilderSchema;
use Gadya\Cms\Forms\Builder\FormSchemaValidator;

/**
 * What both the create and the edit screen do before a form is saved:
 * flatten the builder's questions into the stored list, and refuse a
 * form that could not be filled in, saying why.
 */
final class SavesForms
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data, Page $page): array
    {
        $data['fields'] = FormBuilderSchema::fromBuilder((array) ($data['fields'] ?? []));
        $data['settings'] = self::cleanSettings((array) ($data['settings'] ?? []));
        $data['messages'] = array_filter((array) ($data['messages'] ?? []), fn ($message): bool => is_string($message) && trim($message) !== '');

        $errors = app(FormSchemaValidator::class)->errors($data['fields']);

        if ($errors !== []) {
            Notification::make()
                ->danger()
                ->title('The form cannot be saved yet')
                ->body(implode("\n", array_slice($errors, 0, 6)))
                ->persistent()
                ->send();

            throw new Halt;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private static function cleanSettings(array $settings): array
    {
        $emails = fn (mixed $list): array => array_values(array_unique(array_filter(
            array_map(fn ($address): string => strtolower(trim((string) $address)), (array) $list),
            fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        )));

        $settings['notify'] = $emails($settings['notify'] ?? []);
        $settings['routes'] = array_values(array_map(
            fn (array $route): array => [...$route, 'emails' => $emails($route['emails'] ?? []), 'instead' => (bool) ($route['instead'] ?? false)],
            array_filter((array) ($settings['routes'] ?? []), fn ($route): bool => is_array($route) && filled($route['field'] ?? null)),
        ));
        $settings['webhooks'] = array_values(array_filter((array) ($settings['webhooks'] ?? []), fn ($hook): bool => is_array($hook) && filled($hook['url'] ?? null)));

        return $settings;
    }
}
