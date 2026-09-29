<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Gadya\Cms\Forms\Builder\FieldType;
use Gadya\Cms\Forms\Builder\FieldTypes;
use Gadya\Cms\Forms\Builder\FormLogic;
use Gadya\Cms\Forms\Builder\FormSchema;
use Illuminate\Support\Str;

/**
 * The drag-and-drop list of questions on a form's edit screen: one block
 * per kind of field in the registry, so a kind a site adds shows up here
 * by itself.
 *
 * Filament's Builder keeps each question as `['type' => ..., 'data' =>
 * [...]]`; the form is stored as a flat list (see FormSchema), so the
 * edit screen converts on the way in and out with `toBuilder()` and
 * `fromBuilder()`.
 */
final class FormBuilderSchema
{
    public static function builder(): Builder
    {
        return Builder::make('fields')
            ->hiddenLabel()
            ->blocks(fn (): array => array_values(array_map(self::block(...), app(FieldTypes::class)->all())))
            ->addActionLabel('Add a question')
            ->blockPickerColumns(['default' => 2, 'lg' => 3])
            ->blockPickerWidth('3xl')
            ->blockNumbers(false)
            ->collapsible()
            ->cloneable()
            ->reorderableWithDragAndDrop()
            ->live()
            ->columnSpanFull();
    }

    /**
     * @param  array<array-key, mixed>  $fields  As stored
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public static function toBuilder(array $fields): array
    {
        return array_map(function (array $field): array {
            $type = $field['type'];
            unset($field['type']);

            return ['type' => $type, 'data' => $field];
        }, FormSchema::normalise($fields));
    }

    /**
     * @param  array<array-key, mixed>  $state  As the Builder holds it
     * @return list<array<string, mixed>>
     */
    public static function fromBuilder(array $state): array
    {
        return FormSchema::normalise(array_values($state));
    }

    private static function block(FieldType $type): Block
    {
        return Block::make($type->key)
            ->label(fn (?array $state): string => filled($state['label'] ?? null)
                ? Str::limit((string) $state['label'], 60).' · '.$type->label
                : $type->label)
            ->icon($type->getIcon())
            ->schema(self::fieldsFor($type))
            ->columns(2);
    }

    /**
     * @return list<mixed>
     */
    private static function fieldsFor(FieldType $type): array
    {
        return match ($type->key) {
            'divider' => [Text::make('A line between one part of the form and the next.')],
            'heading' => [
                TextInput::make('label')->label('Heading')->required()->maxLength(160)->live(onBlur: true)->columnSpanFull(),
                TextInput::make('help')->label('Words under it')->maxLength(300)->columnSpanFull(),
                self::logic(),
            ],
            'paragraph' => [
                Textarea::make('text')->label('Text')->required()->rows(4)->maxLength(3000)->helperText('Use **bold**, [a link](https://...) and lines starting with - for a list.')->columnSpanFull(),
                self::logic(),
            ],
            'page_break' => [
                TextInput::make('label')->label('Title of the next step')->placeholder('e.g. Your details')->maxLength(120)->live(onBlur: true)->columnSpanFull(),
                self::logic('Show this step'),
            ],
            default => self::inputFields($type),
        };
    }

    /**
     * @return list<mixed>
     */
    private static function inputFields(FieldType $type): array
    {
        $key = $type->key;
        $textLike = in_array($key, ['short_text', 'long_text', 'email', 'phone', 'number', 'currency', 'url', 'select', 'multi_select', 'country', 'us_state'], true) || $type->getView() !== null;

        $label = $key === 'consent'
            ? Textarea::make('label')->label('The exact words they agree to')->helperText('Kept word for word with every enquiry, as the record of what they agreed to.')->required()->rows(3)->maxLength(1000)
            : TextInput::make('label')->label($key === 'hidden' ? 'What it is (only you see this)' : 'Question')->required($key !== 'hidden')->maxLength(200);

        return array_values(array_filter([
            $label->live(onBlur: true)->columnSpanFull(),
            $key === 'hidden' ? null : TextInput::make('help')->label('Help under the question')->maxLength(300)->columnSpanFull(),
            $textLike ? TextInput::make('placeholder')->label('Example answer shown in the box')->maxLength(120) : null,
            in_array($key, ['hidden', 'mailing_list'], true) ? null : Toggle::make('required')->label('They must answer this')->inline(false),
            $type->hasChoices() ? Repeater::make('options')
                ->label('Choices')
                ->schema([
                    TextInput::make('label')->hiddenLabel()->placeholder('A choice')->required()->maxLength(120),
                    Hidden::make('key'),
                ])
                ->addActionLabel('Add a choice')
                ->defaultItems(2)
                ->minItems(1)
                ->reorderable()
                ->columnSpanFull() : null,
            ...$type->settingsSchema(),
            $key === 'hidden' ? null : Select::make('width')->label('Width')->options(['full' => 'Full width', 'half' => 'Half (side by side with the next)'])->default('full')->selectablePlaceholder(false),
            TextInput::make('default')->label($key === 'hidden' ? 'Value' : 'Starts filled in with')->maxLength(500),
            TextInput::make('key')
                ->label('Name in the inbox and exports')
                ->helperText('Made from the question if left empty. Changing it later starts a new column in exports.')
                ->maxLength(40)
                ->regex('/^[a-z][a-z0-9_]*$/'),
            Toggle::make('prefill')->label('Can be filled in from the web address')->helperText('e.g. ?name_in_the_inbox=value on a link to the page.')->default($key === 'hidden')->inline(false),
            $key === 'hidden' ? null : self::logic(),
        ]));
    }

    /**
     * "Show this question only when...": the same rules the page runs as
     * the visitor types, and the server runs again when the form is sent.
     */
    private static function logic(string $label = 'Show this question'): Fieldset
    {
        return Fieldset::make('When to show it')
            ->schema([
                Select::make('logic.action')
                    ->label($label)
                    ->options(['show' => 'Only when...', 'hide' => 'Always, except when...'])
                    ->placeholder('Always')
                    ->live(),
                Select::make('logic.match')
                    ->label('Which conditions')
                    ->options(['all' => 'All of them are true', 'any' => 'Any of them is true'])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get): bool => filled($get('logic.action'))),
                Repeater::make('logic.rules')
                    ->label('Conditions')
                    ->schema([
                        Select::make('field')
                            ->label('Question')
                            ->options(fn ($livewire): array => self::questionOptions($livewire))
                            ->required()
                            ->searchable(),
                        Select::make('operator')
                            ->label('Answer')
                            ->options(FormLogic::operatorLabels())
                            ->default('equals')
                            ->required()
                            ->live(),
                        TextInput::make('value')
                            ->label('Value')
                            ->maxLength(200)
                            ->visible(fn (Get $get): bool => ! in_array($get('operator'), ['empty', 'not_empty'], true)),
                    ])
                    ->columns(3)
                    ->defaultItems(1)
                    ->addActionLabel('Add a condition')
                    ->visible(fn (Get $get): bool => filled($get('logic.action')))
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->columnSpanFull();
    }

    /**
     * Every question on the form, for a condition to read.
     *
     * @return array<string, string>
     */
    public static function questionOptions(mixed $livewire): array
    {
        $state = data_get($livewire, 'data.fields');
        $options = [];

        foreach ((new FormSchema(is_array($state) ? array_values($state) : []))->inputs() as $field) {
            $options[$field['key']] = Str::limit($field['label'] !== '' ? $field['label'] : $field['key'], 60);
        }

        return $options;
    }
}
