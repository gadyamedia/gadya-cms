<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Sms\PhoneNumbers;
use Gadya\Cms\Sms\TextAlerts;
use Gadya\Cms\Sms\TwilioSettings;
use UnitEnum;

/**
 * The client's own Twilio account, for text alerts when an enquiry
 * arrives. The auth token is stored encrypted and never shown back.
 *
 * @property-read Schema $form
 */
class TextMessages extends Page
{
    protected string $view = 'gadya-cms::filament.pages.simple-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Text messages';

    protected static ?string $title = 'Text messages';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(TwilioSettings $twilio): void
    {
        $this->form->fill([
            'sid' => $twilio->accountSid(),
            'from' => $twilio->from() === null ? null : PhoneNumbers::display($twilio->from()),
            'service' => $twilio->messagingServiceSid(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $twilio = app(TwilioSettings::class);

        return $schema
            ->components([
                Form::make([
                    Section::make('Your Twilio account')
                        ->description('Enquiry alerts are texted from your own Twilio account, to the numbers you list on each form. You pay Twilio for each text.')
                        ->schema([
                            Text::make('Before business texts are delivered, your Twilio number must be registered: toll-free verification for a toll-free number, or A2P 10DLC registration for a local number. Twilio walks you through it under Messaging → Regulatory Compliance. Until then, texts may be blocked by the phone networks.')->columnSpanFull(),
                            TextInput::make('sid')->label('Account SID')->placeholder('AC...')->rule('regex:/^AC[0-9a-fA-F]{32}$/')->maxLength(34),
                            TextInput::make('token')
                                ->label('Auth token')
                                ->password()
                                ->placeholder($twilio->authToken() !== null ? '•••••••• saved - leave empty to keep it' : '')
                                ->maxLength(100),
                            TextInput::make('from')->label('Send from this Twilio number')->placeholder('(732) 555-0100')->helperText('Or use a Messaging Service below instead.')->maxLength(30),
                            TextInput::make('service')->label('Messaging Service SID (optional)')->placeholder('MG...')->rule('regex:/^MG[0-9a-fA-F]{32}$/')->maxLength(34),
                        ])
                        ->columns(2),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
            ])
            ->statePath('data');
    }

    public function save(TwilioSettings $twilio): void
    {
        $state = $this->form->getState();

        if (filled($state['from'] ?? null) && PhoneNumbers::normalise($state['from']) === null) {
            Notification::make()->danger()->title('That sending number does not look right')->send();

            return;
        }

        $twilio->save($state);

        Notification::make()->success()->title('Saved')->body($twilio->isConfigured() ? 'Forms can now text their alerts.' : 'Texting needs the SID, the token and a number or Messaging Service.')->send();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test text')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->visible(fn (): bool => app(TwilioSettings::class)->isConfigured())
                ->schema([TextInput::make('number')->label('Your mobile number')->required()->maxLength(30)])
                ->action(function (array $data, TextAlerts $alerts): void {
                    $error = $alerts->test($data['number']);

                    $error === null
                        ? Notification::make()->success()->title('Sent')->body('It should arrive in a few seconds.')->send()
                        : Notification::make()->danger()->title('The text was not sent')->body($error)->persistent()->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
