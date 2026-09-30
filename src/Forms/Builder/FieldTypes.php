<?php

namespace Gadya\Cms\Forms\Builder;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Gadya\Cms\Support\SiteTimezone;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Every kind of question a builder form can ask, by key.
 *
 * The package registers its own here when it is first resolved; a site
 * registers more with `register()`, and they appear in the builder, are
 * drawn on the page and are checked like the rest. A type registered with
 * the key of a built-in one replaces it.
 */
class FieldTypes
{
    /** @var array<string, FieldType> */
    private array $types = [];

    public function __construct()
    {
        foreach ($this->builtIn() as $type) {
            $this->register($type);
        }
    }

    public function register(FieldType $type): void
    {
        $this->types[$type->key] = $type;
    }

    public function get(string $key): ?FieldType
    {
        return $this->types[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /** @return array<string, FieldType> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return array_map(fn (FieldType $type): string => $type->label, $this->types);
    }

    /**
     * @return list<FieldType>
     */
    private function builtIn(): array
    {
        $length = fn (int $max): array => [
            TextInput::make('rules.min_length')->label('Shortest answer (characters)')->numeric()->minValue(0),
            TextInput::make('rules.max_length')->label('Longest answer (characters)')->numeric()->minValue(1)->placeholder((string) $max),
        ];

        $range = fn (string $unit = 'number'): array => [
            TextInput::make('rules.min')->label('Smallest '.$unit)->numeric(),
            TextInput::make('rules.max')->label('Largest '.$unit)->numeric(),
        ];

        $uploads = fn (string $defaults): array => [
            TagsInput::make('rules.accept')->label('Allowed kinds of file')->placeholder('e.g. pdf')->helperText('Leave empty for '.$defaults.'.'),
            TextInput::make('rules.max_kb')->label('Largest file (KB)')->numeric()->minValue(1)->placeholder('10240'),
            Toggle::make('rules.multiple')->label('Allow more than one file'),
        ];

        $options = fn (array $field): array => array_values(array_filter(array_map(
            fn ($option): ?string => is_array($option) ? ($option['key'] ?? null) : null,
            (array) ($field['options'] ?? []),
        )));

        $label = fn (mixed $value, array $field): mixed => collect((array) ($field['options'] ?? []))
            ->firstWhere('key', $value)['label'] ?? $value;

        $labels = fn (mixed $value, array $field): array => array_values(array_map(fn ($one) => $label($one, $field), array_filter((array) $value, fn ($one): bool => is_string($one) && $one !== '')));

        $yes = fn (mixed $value): string => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';

        $int = fn (array $field, string $key, ?int $default = null): ?int => is_numeric($field['rules'][$key] ?? null) ? (int) $field['rules'][$key] : $default;

        $uploadRules = function (array $field, string $defaults) use ($int): array {
            $accept = array_values(array_filter(array_map(
                fn ($ext): string => strtolower(trim((string) $ext, " .\t")),
                (array) ($field['rules']['accept'] ?? []),
            )));

            return [
                '' => ['array', 'max:'.(($field['rules']['multiple'] ?? false) ? 10 : 1)],
                '*' => ['file', 'extensions:'.implode(',', $accept ?: explode(',', $defaults)), 'max:'.min($int($field, 'max_kb', 10240), (int) config('gadya-cms.forms.builder.uploads.max_kb', 20480))],
            ];
        };

        return [
            FieldType::make('short_text', 'Short answer')->group('Text')->icon('heroicon-o-bars-2')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'string',
                    'max:'.$int($field, 'max_length', 255),
                    $int($field, 'min_length') ? 'min:'.$int($field, 'min_length') : null,
                    filled($field['rules']['pattern'] ?? null) ? 'regex:/'.str_replace('/', '\/', (string) $field['rules']['pattern']).'/u' : null,
                ]))])
                ->settings(fn (): array => [...$length(255), TextInput::make('rules.pattern')->label('Must match (a regular expression, for developers)')->maxLength(200)->columnSpanFull()]),
            FieldType::make('long_text', 'Long answer')->group('Text')->icon('heroicon-o-bars-3-bottom-left')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'string',
                    'max:'.$int($field, 'max_length', 5000),
                    $int($field, 'min_length') ? 'min:'.$int($field, 'min_length') : null,
                ]))])
                ->settings(fn (): array => $length(5000)),
            FieldType::make('email', 'Email address')->group('Contact')->icon('heroicon-o-at-symbol')
                ->rules(fn (): array => ['' => ['string', 'email', 'max:255']])
                ->normaliseUsing(fn (mixed $value): string => Str::lower(trim((string) $value))),
            FieldType::make('phone', 'Phone number')->group('Contact')->icon('heroicon-o-phone')
                ->rules(fn (): array => ['' => ['string', 'max:40', 'regex:/^[0-9+()\-.\s]{7,40}$/']]),
            FieldType::make('number', 'Number')->group('Numbers')->icon('heroicon-o-hashtag')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'numeric',
                    isset($field['rules']['min']) && is_numeric($field['rules']['min']) ? 'min:'.$field['rules']['min'] : null,
                    isset($field['rules']['max']) && is_numeric($field['rules']['max']) ? 'max:'.$field['rules']['max'] : null,
                ]))])
                ->settings(fn (): array => $range()),
            FieldType::make('currency', 'Amount of money')->group('Numbers')->icon('heroicon-o-currency-dollar')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'numeric',
                    'min:'.(is_numeric($field['rules']['min'] ?? null) ? $field['rules']['min'] : 0),
                    isset($field['rules']['max']) && is_numeric($field['rules']['max']) ? 'max:'.$field['rules']['max'] : null,
                ]))])
                ->settings(fn (): array => $range('amount'))
                ->normaliseUsing(fn (mixed $value): string => number_format((float) $value, 2, '.', '')),
            FieldType::make('url', 'Web address')->group('Contact')->icon('heroicon-o-link')
                ->rules(fn (): array => ['' => ['string', 'url:http,https', 'max:500']]),
            FieldType::make('select', 'Dropdown')->group('Choices')->icon('heroicon-o-chevron-up-down')->choices()
                ->rules(fn (array $field): array => ['' => ['string', Rule::in($options($field))]])
                ->normaliseUsing($label),
            FieldType::make('multi_select', 'Dropdown, several answers')->group('Choices')->icon('heroicon-o-queue-list')->choices(multiple: true)
                ->rules(fn (array $field): array => ['' => ['array'], '*' => ['string', Rule::in($options($field))]])
                ->normaliseUsing($labels),
            FieldType::make('radio', 'One of a list')->group('Choices')->icon('heroicon-o-stop-circle')->choices()
                ->rules(fn (array $field): array => ['' => ['string', Rule::in($options($field))]])
                ->normaliseUsing($label),
            FieldType::make('checkboxes', 'Tick all that apply')->group('Choices')->icon('heroicon-o-check-circle')->choices(multiple: true)
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'array',
                    $int($field, 'min') ? 'min:'.$int($field, 'min') : null,
                    $int($field, 'max') ? 'max:'.$int($field, 'max') : null,
                ])), '*' => ['string', Rule::in($options($field))]])
                ->settings(fn (): array => [
                    TextInput::make('rules.min')->label('Fewest ticks')->numeric()->minValue(0),
                    TextInput::make('rules.max')->label('Most ticks')->numeric()->minValue(1),
                ])
                ->normaliseUsing($labels),
            FieldType::make('checkbox', 'A single tick box')->group('Choices')->icon('heroicon-o-check')
                ->rules(fn (array $field): array => ['' => ($field['required'] ?? false) ? ['accepted'] : ['nullable', 'boolean']])
                ->normaliseUsing($yes),
            FieldType::make('yes_no', 'Yes or no')->group('Choices')->icon('heroicon-o-hand-thumb-up')
                ->rules(fn (): array => ['' => ['string', Rule::in(['yes', 'no'])]])
                ->normaliseUsing(fn (mixed $value): string => $value === 'yes' ? 'Yes' : 'No'),
            FieldType::make('date', 'Date')->group('Date and time')->icon('heroicon-o-calendar')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'date_format:Y-m-d',
                    ($field['rules']['future'] ?? false) ? 'after_or_equal:'.app(SiteTimezone::class)->now()->toDateString() : null,
                ]))])
                ->settings(fn (): array => [Toggle::make('rules.future')->label('Today or later only')]),
            FieldType::make('time', 'Time')->group('Date and time')->icon('heroicon-o-clock')
                ->rules(fn (): array => ['' => ['date_format:H:i']]),
            FieldType::make('datetime', 'Date and time')->group('Date and time')->icon('heroicon-o-calendar-days')
                ->rules(fn (array $field): array => ['' => array_values(array_filter([
                    'date_format:Y-m-d\TH:i',
                    ($field['rules']['future'] ?? false) ? 'after_or_equal:'.app(SiteTimezone::class)->now()->toDateString() : null,
                ]))])
                ->settings(fn (): array => [Toggle::make('rules.future')->label('Today or later only')])
                ->normaliseUsing(fn (mixed $value): string => str_replace('T', ' ', (string) $value)),
            FieldType::make('file', 'File upload')->group('Files')->icon('heroicon-o-paper-clip')->file()
                ->rules(fn (array $field): array => $uploadRules($field, 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,heic'))
                ->settings(fn (): array => $uploads('PDFs, documents, spreadsheets and photos')),
            FieldType::make('image', 'Photo upload')->group('Files')->icon('heroicon-o-photo')->file()
                ->rules(fn (array $field): array => $uploadRules($field, 'jpg,jpeg,png,webp,heic,gif'))
                ->settings(fn (): array => $uploads('photos (jpg, png, webp, heic, gif)')),
            FieldType::make('rating', 'Star rating')->group('Numbers')->icon('heroicon-o-star')
                ->rules(fn (array $field): array => ['' => ['integer', 'min:1', 'max:'.$int($field, 'stars', 5)]])
                ->settings(fn (): array => [TextInput::make('rules.stars')->label('How many stars')->numeric()->minValue(3)->maxValue(10)->placeholder('5')])
                ->normaliseUsing(fn (mixed $value): int => (int) $value),
            FieldType::make('scale', 'Scale (0 to 10)')->group('Numbers')->icon('heroicon-o-chart-bar')
                ->rules(fn (array $field): array => ['' => ['integer', 'min:'.$int($field, 'min', 0), 'max:'.$int($field, 'max', 10)]])
                ->settings(fn (): array => [
                    TextInput::make('rules.min')->label('From')->numeric()->placeholder('0'),
                    TextInput::make('rules.max')->label('To')->numeric()->placeholder('10'),
                    TextInput::make('rules.low_label')->label('Words under the lowest')->placeholder('Not likely')->maxLength(40),
                    TextInput::make('rules.high_label')->label('Words under the highest')->placeholder('Very likely')->maxLength(40),
                ])
                ->normaliseUsing(fn (mixed $value): int => (int) $value),
            FieldType::make('slider', 'Slider')->group('Numbers')->icon('heroicon-o-adjustments-horizontal')
                ->rules(fn (array $field): array => ['' => ['numeric', 'min:'.$int($field, 'min', 0), 'max:'.$int($field, 'max', 100)]])
                ->settings(fn (): array => [
                    ...$range(),
                    TextInput::make('rules.step')->label('Moves in steps of')->numeric()->minValue(1)->placeholder('1'),
                ]),
            FieldType::make('name', 'Name (first and last)')->group('Contact')->icon('heroicon-o-user')->parts(['first', 'last'])
                ->rules(fn (): array => ['' => ['array'], 'first' => ['string', 'max:80'], 'last' => ['string', 'max:80']])
                ->normaliseUsing(fn (mixed $value): string => trim(implode(' ', array_filter([
                    trim((string) ($value['first'] ?? '')),
                    trim((string) ($value['last'] ?? '')),
                ])))),
            FieldType::make('address', 'Address')->group('Contact')->icon('heroicon-o-map-pin')->parts(['line1', 'line2', 'city', 'state', 'zip'])
                ->rules(fn (): array => [
                    '' => ['array'],
                    'line1' => ['string', 'max:160'],
                    'line2' => ['nullable', 'string', 'max:160'],
                    'city' => ['string', 'max:80'],
                    'state' => ['string', Rule::in(array_keys(Places::usStates()))],
                    'zip' => ['string', 'regex:/^\d{5}(-\d{4})?$/'],
                ])
                ->normaliseUsing(fn (mixed $value): string => trim(implode(', ', array_filter([
                    trim((string) ($value['line1'] ?? '')),
                    trim((string) ($value['line2'] ?? '')),
                    trim((string) ($value['city'] ?? '')),
                    trim(($value['state'] ?? '').' '.($value['zip'] ?? '')),
                ])))),
            FieldType::make('country', 'Country')->group('Contact')->icon('heroicon-o-globe-americas')
                ->rules(fn (): array => ['' => ['string', Rule::in(array_keys(Places::countries()))]])
                ->normaliseUsing(fn (mixed $value): string => Places::countries()[$value] ?? (string) $value),
            FieldType::make('us_state', 'US state')->group('Contact')->icon('heroicon-o-flag')
                ->rules(fn (): array => ['' => ['string', Rule::in(array_keys(Places::usStates()))]])
                ->normaliseUsing(fn (mixed $value): string => Places::usStates()[$value] ?? (string) $value),
            FieldType::make('hidden', 'Hidden value')->group('Special')->icon('heroicon-o-eye-slash')
                ->rules(fn (): array => ['' => ['nullable', 'string', 'max:500']]),
            FieldType::make('consent', 'Consent')->group('Special')->icon('heroicon-o-shield-check')
                ->rules(fn (array $field): array => ['' => ($field['required'] ?? false) ? ['accepted'] : ['nullable', 'boolean']])
                ->settings(fn (): array => [Toggle::make('rules.callback')->label('This is consent to be rung back')->helperText('On a call-back form, ticking it lets the Gadya portal ring the visitor back.')])
                ->normaliseUsing($yes),
            FieldType::make('signature', 'Signature')->group('Special')->icon('heroicon-o-pencil')->file()
                /* A drawing arrives as a PNG; without JavaScript, a typed name. */
                ->rules(fn (): array => ['' => ['string', 'max:400000', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && str_starts_with($value, 'data:') && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $value) !== 1) {
                        $fail('The signature could not be read. Please sign again.');
                    }

                    if (is_string($value) && ! str_starts_with($value, 'data:') && mb_strlen($value) > 120) {
                        $fail('Please type just your name to sign.');
                    }
                }]]),
            FieldType::make('mailing_list', 'Join the mailing list')->group('Special')->icon('heroicon-o-envelope-open')
                ->rules(fn (): array => ['' => ['nullable', 'boolean']])
                ->normaliseUsing($yes),
            FieldType::make('heading', 'Heading')->group('Layout')->icon('heroicon-o-h1')->layout(),
            FieldType::make('paragraph', 'Paragraph')->group('Layout')->icon('heroicon-o-document-text')->layout(),
            FieldType::make('divider', 'Divider')->group('Layout')->icon('heroicon-o-minus')->layout(),
            FieldType::make('page_break', 'New step')->group('Layout')->icon('heroicon-o-arrow-right-circle')->layout(),
        ];
    }
}
