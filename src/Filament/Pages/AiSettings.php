<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Ai\Agents\ConnectionCheck;
use Gadya\Cms\Ai\AiProviders;
use Gadya\Cms\Ai\AiSettings as Settings;
use Gadya\Cms\Ai\Prompter;
use Throwable;
use UnitEnum;

/**
 * Which AI service the site uses, and how it should sound.
 *
 * The key is written but never read back: the form shows only that one is
 * saved, so a screenshot of this screen gives nothing away.
 *
 * @property-read Schema $form
 */
class AiSettings extends Page
{
    protected string $view = 'gadya-cms::filament.pages.ai-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'AI';

    protected static ?string $title = 'AI';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(Settings $settings): void
    {
        $this->form->fill([
            'provider' => $settings->provider(),
            'model' => $settings->model(),
            'url' => $settings->baseUrl(),
            'key' => null,
            'voice' => $settings->voice(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Service')
                        ->description('Where articles and search snippets are written. The key is stored encrypted and never shown again.')
                        ->schema([
                            Select::make('provider')
                                ->options(AiProviders::options())
                                ->required()
                                ->live()
                                ->native(false),
                            TextInput::make('model')
                                ->required()
                                ->maxLength(120)
                                ->datalist(fn (Get $get): array => AiProviders::modelsFor($get('provider')))
                                ->helperText('Pick a suggestion or type any model the service offers.'),
                            TextInput::make('key')
                                ->label('API key')
                                ->password()
                                ->revealable()
                                ->maxLength(500)
                                ->autocomplete('off')
                                ->placeholder(fn (): string => app(Settings::class)->apiKey() !== null ? 'A key is saved. Paste a new one to replace it.' : 'Paste the key from the service')
                                ->helperText(fn (Get $get): ?string => AiProviders::all()[$get('provider')]['key_hint'] ?? null)
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                            TextInput::make('url')
                                ->label('Server address')
                                ->url()
                                ->maxLength(255)
                                ->placeholder(fn (Get $get): ?string => AiProviders::all()[$get('provider')]['url'] ?? null)
                                ->visible(fn (Get $get): bool => AiProviders::needsUrl($get('provider')))
                                ->required(fn (Get $get): bool => AiProviders::needsUrl($get('provider'))),
                        ])
                        ->columns(2),
                    Section::make('Voice')
                        ->description('How the business describes itself. Every article and snippet is written from this, so the more honest and specific it is, the less the client has to fix.')
                        ->schema([
                            TextInput::make('voice.business')->label('Business name')->required()->maxLength(120),
                            TextInput::make('voice.tone')->label('Tone of voice')->maxLength(160)->placeholder('Warm, clear and confident'),
                            Textarea::make('voice.description')
                                ->label('What the business does')
                                ->rows(4)
                                ->maxLength(2000)
                                ->columnSpanFull()
                                ->helperText('Services, what makes it different, anything a new writer would need to know on day one.'),
                            TextInput::make('voice.audience')->label('Who it is for')->maxLength(255)->columnSpanFull(),
                            TextInput::make('voice.area')->label('Service area')->maxLength(255),
                            TextInput::make('voice.phone')->label('Phone number')->maxLength(40),
                            TextInput::make('voice.contact_path')->label('Contact page address')->maxLength(120)->placeholder('/contact'),
                            Textarea::make('voice.rules')
                                ->label('House rules')
                                ->rows(3)
                                ->maxLength(2000)
                                ->columnSpanFull()
                                ->helperText('Things it must never say, words to avoid, claims it may not make.'),
                        ])
                        ->columns(2),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(Settings $settings): void
    {
        $data = $this->form->getState();

        $settings->save([
            'provider' => $data['provider'] ?? null,
            'model' => $data['model'] ?? null,
            'key' => $data['key'] ?? null,
            'url' => $data['url'] ?? null,
        ]);
        $settings->saveVoice($data['voice'] ?? []);

        $this->form->fill([...$data, 'key' => null]);

        Notification::make()->success()->title('AI settings saved')->send();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->action(function (Prompter $prompter, Settings $settings): void {
                    if (! $settings->isConfigured()) {
                        Notification::make()->warning()->title('Save the settings first')->body('A provider, model and key are needed before anything can be tested.')->send();

                        return;
                    }

                    try {
                        $reply = trim($prompter->prompt(app(ConnectionCheck::class), 'Are you there?')->text);
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title('The service did not answer')->body(mb_substr($exception->getMessage(), 0, 300))->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title('Connected')->body("{$settings->model()} replied: ".mb_substr($reply, 0, 60))->send();
                }),
            Action::make('forgetKey')
                ->label('Forget key')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (Settings $settings): bool => $settings->apiKey() !== null)
                ->requiresConfirmation()
                ->modalDescription('Articles and snippets cannot be written until a new key is saved.')
                ->action(function (Settings $settings): void {
                    $settings->forgetKey();

                    Notification::make()->success()->title('Key forgotten')->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.ai.gate', 'manage-users')) ?? false;
    }
}
