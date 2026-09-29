<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Gadya\Cms\Forms\Builder\FormLogic;

/**
 * Who hears about an enquiry, and what the person who sent it hears back.
 */
final class FormNotificationSchema
{
    public const MERGE_HELP = 'Use {name} for their name, {business} for yours, {form} for this form, {all_answers} for every answer, or {name_in_the_inbox} of any question.';

    /**
     * @return list<mixed>
     */
    public static function fields(): array
    {
        return [
            Section::make('Who is told about a new enquiry')
                ->description('Everyone here gets an email the moment one arrives. Replying goes straight to whoever sent it.')
                ->schema([
                    self::emails('settings.notify', 'Email addresses'),
                    ...self::phones('settings.notify_sms'),
                    TextInput::make('settings.email_subject')->label('Subject')->placeholder('New {form} enquiry from {name}')->maxLength(200)->columnSpanFull(),
                    Textarea::make('settings.email_body')->label('Message above the answers')->helperText(self::MERGE_HELP)->rows(3)->maxLength(2000)->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Send some enquiries elsewhere')
                ->description('For example: if "What do you need?" is Catering, also tell catering@yourbusiness.com.')
                ->schema([
                    Repeater::make('settings.routes')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('field')->label('When the answer to')->options(fn ($livewire): array => FormBuilderSchema::questionOptions($livewire))->required()->searchable(),
                            Select::make('operator')->label('')->options(FormLogic::operatorLabels())->default('equals')->required()->live(),
                            TextInput::make('value')->label('This')->maxLength(200)->visible(fn (Get $get): bool => ! in_array($get('operator'), ['empty', 'not_empty'], true)),
                            self::emails('emails', 'Email these addresses'),
                            ...self::phones('sms'),
                            Toggle::make('instead')->label('Only these - not the usual list')->inline(false),
                        ])
                        ->columns(3)
                        ->addActionLabel('Add a rule')
                        ->defaultItems(0),
                ]),
            Section::make('Reply to whoever sent it')
                ->description('Sent only when the form asks for an email address.')
                ->schema([
                    Toggle::make('settings.autoreply.enabled')->label('Send a reply')->live(),
                    TextInput::make('settings.autoreply.subject')->label('Subject')->placeholder('Thank you for getting in touch with {business}')->maxLength(200)->visible(fn (Get $get): bool => (bool) $get('settings.autoreply.enabled')),
                    Textarea::make('settings.autoreply.body')->label('Message')->helperText(self::MERGE_HELP.' A blank line starts a new paragraph.')->rows(8)->maxLength(3000)->visible(fn (Get $get): bool => (bool) $get('settings.autoreply.enabled')),
                ]),
        ];
    }

    private static function emails(string $path, string $label): TagsInput
    {
        return TagsInput::make($path)
            ->label($label)
            ->placeholder('Add an email address')
            ->nestedRecursiveRules(['email'])
            ->columnSpanFull();
    }

    /**
     * Mobile numbers for text alerts, shown once texting is set up.
     *
     * @return list<mixed>
     */
    public static function phones(string $path): array
    {
        return [];
    }
}
