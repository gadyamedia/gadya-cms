<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Gadya\Cms\Forms\Builder\FormLogic;
use Gadya\Cms\Mail\SenderPanel;
use Gadya\Cms\Sms\PhoneNumbers;
use Gadya\Cms\Sms\TextAlerts;

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
            Section::make('Send a test')
                ->key('sendTestSection')
                ->description('Check who the email comes from, who gets it, and that it arrives - before a real enquiry does.')
                ->headerActions([SendTestAction::make()])
                ->schema([
                    View::make('gadya-cms::filament.mail.sender-panel')
                        ->viewData(fn (): array => ['panel' => app(SenderPanel::class)->describe(), 'limit' => 3]),
                ]),
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
        if (! app(TextAlerts::class)->enabled()) {
            return [
                Text::make('Add your Twilio details under Settings → Text messages to send text alerts.')->columnSpanFull(),
            ];
        }

        return [
            TagsInput::make($path)
                ->label('Mobile numbers to text')
                ->placeholder('Add a mobile number')
                ->nestedRecursiveRules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (PhoneNumbers::normalise($value) === null) {
                        $fail('"'.$value.'" does not look like a mobile number.');
                    }
                }])
                ->helperText(fn (?array $state): string => self::numberStates((array) $state))
                ->columnSpanFull(),
        ];
    }

    /**
     * How each number is doing, in a line under the box: a number whose
     * owner replied STOP gets no more texts until they text START.
     *
     * @param  array<array-key, mixed>  $numbers
     */
    private static function numberStates(array $numbers): string
    {
        $alerts = app(TextAlerts::class);
        $notes = [];

        foreach (PhoneNumbers::normaliseAll($numbers) as $number) {
            $state = $alerts->state($number);

            $notes[] = PhoneNumbers::display($number).': '.match (true) {
                filled($state['opted_out_at'] ?? null) => 'replied STOP - no texts until they text START to your Twilio number',
                filled($state['last_error'] ?? null) => 'last text failed ('.$state['last_error'].')',
                filled($state['last_sent_at'] ?? null) => 'receiving texts',
                default => 'not texted yet',
            };
        }

        return $notes === [] ? 'Each gets a short text the moment an enquiry arrives. The first one says how to opt out.' : implode(' · ', $notes);
    }
}
