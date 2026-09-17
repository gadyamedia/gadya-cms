<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Options\Options;
use Gadya\Cms\Search\PageSpeed;
use Gadya\Cms\Search\SearchConsole;
use Throwable;
use UnitEnum;

/**
 * The two Google services the dashboard can draw on: Search Console for
 * what people searched, PageSpeed Insights for how fast the pages are.
 *
 * @property-read Schema $form
 */
class SearchSettings extends Page
{
    protected string $view = 'gadya-cms::filament.pages.search-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Search & speed';

    protected static ?string $title = 'Search & speed';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SearchConsole $console): void
    {
        $this->form->fill(['property' => $console->property()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Google Search Console')
                        ->description('What people searched for to find the site, and which pages they landed on. Needs a service account added to the property as a user.')
                        ->schema([
                            TextInput::make('property')
                                ->label('Property')
                                ->placeholder('sc-domain:example.com  or  https://www.example.com/')
                                ->maxLength(255)
                                ->helperText('Exactly as it appears in Search Console.'),
                            Textarea::make('service_account')
                                ->label('Service account key (JSON)')
                                ->rows(4)
                                ->placeholder(fn (SearchConsole $console): string => $console->account() !== null ? 'A key is saved for '.$console->account()->email().'. Paste a new one to replace it.' : 'Paste the whole JSON file from Google Cloud')
                                ->helperText('Stored encrypted and never shown again.')
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                        ]),
                    Section::make('PageSpeed Insights')
                        ->description('Lighthouse, run on Google\'s machines against the live site. Works without a key at a low rate; a free key from Google Cloud lifts the limit.')
                        ->schema([
                            TextInput::make('pagespeed_key')
                                ->label('API key (optional)')
                                ->password()
                                ->revealable()
                                ->maxLength(200)
                                ->placeholder(fn (PageSpeed $pageSpeed): string => $pageSpeed->key() !== null ? 'A key is saved. Paste a new one to replace it.' : 'AIza...')
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                        ]),
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

    public function save(SearchConsole $console, Options $options): void
    {
        $data = $this->form->getState();

        try {
            $console->save(['property' => $data['property'] ?? null, 'service_account' => $data['service_account'] ?? null]);
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('That key could not be used')->body($exception->getMessage())->send();

            return;
        }

        if (! empty($data['pagespeed_key'])) {
            $options->setSecret('pagespeed.key', (string) $data['pagespeed_key']);
        }

        $this->form->fill(['property' => $data['property'] ?? null]);

        Notification::make()->success()->title('Saved')->send();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetchSearch')
                ->label('Fetch from Google now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (SearchConsole $console): bool => $console->isConfigured())
                ->action(function (SearchConsole $console): void {
                    try {
                        $counts = $console->fetch();
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title('Google did not answer')->body(mb_substr($exception->getMessage(), 0, 300))->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title('Fetched')->body("{$counts['queries']} queries and {$counts['pages']} pages, now on the dashboard.")->send();
                }),
            Action::make('checkSpeed')
                ->label('Check page speed now')
                ->icon(Heroicon::OutlinedBolt)
                ->action(function (PageSpeed $pageSpeed): void {
                    try {
                        $scores = $pageSpeed->checkSite(3);
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title('PageSpeed did not answer')->body(mb_substr($exception->getMessage(), 0, 300))->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title('Checked '.$scores->count().' pages')->body('The scores are on the dashboard.')->send();
                }),
            Action::make('forgetSearch')
                ->label('Forget Google key')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (SearchConsole $console): bool => $console->account() !== null)
                ->requiresConfirmation()
                ->action(function (SearchConsole $console): void {
                    $console->forget();

                    Notification::make()->success()->title('Key forgotten')->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
