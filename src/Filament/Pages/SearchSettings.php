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
use Gadya\Cms\Search\PortalSearchConsole;
use Gadya\Cms\Search\SearchConsole;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

/**
 * The two Google services the dashboard can draw on: Search Console for
 * what people searched, PageSpeed Insights for how fast the pages are.
 *
 * Search Console is connected with one button: Gadya Media's portal does
 * the signing in with Google and holds the access, so the client has no
 * key to make. The old way, pasting a service account key, stays under
 * "Advanced". The card above the form is plain page state and Filament
 * actions, not a nested component, so redrawing the form never calls the
 * portal (its answers are kept for fifteen minutes in any case).
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

        $this->welcomeBackFromGoogle();
    }

    /**
     * What the portal knows, kept for fifteen minutes; null when this site
     * is not paired or the portal could not be asked.
     *
     * @return array<string, mixed>|null
     */
    public function getSearchStatusProperty(): ?array
    {
        return app(PortalSearchConsole::class)->status();
    }

    /**
     * The numbers' last day, for "Data up to": from the same kept answer
     * the dashboard reads, so showing it is not another call.
     */
    public function getSearchDataUpToProperty(): ?string
    {
        $to = app(PortalSearchConsole::class)->performance(28)['to'] ?? null;

        return $to === null ? null : Carbon::parse($to)->format('j F Y');
    }

    /** Google only sends people back to an https address. */
    public function canConnectHere(): bool
    {
        return str_starts_with(static::getUrl(), 'https://');
    }

    public function isPaired(): bool
    {
        return app(PortalSearchConsole::class)->paired();
    }

    public function getHostProperty(): string
    {
        return (string) parse_url(static::getUrl(), PHP_URL_HOST);
    }

    /**
     * Google sends the person back here with `?google=connected` or
     * `?google=failed&reason=...`. Say what happened in plain words, look
     * at the connection afresh, and drop the query so a reload does not
     * say it all again.
     */
    private function welcomeBackFromGoogle(): void
    {
        $outcome = request()->query('google');

        if (! in_array($outcome, ['connected', 'failed'], true)) {
            return;
        }

        $portal = app(PortalSearchConsole::class);
        $portal->forget();
        $portal->status();

        if ($outcome === 'connected') {
            /* The first numbers may not be in yet; whatever is, is kept for the dashboard. */
            rescue(fn (): array => app(SearchConsole::class)->fetch(), [], report: false);

            Notification::make()->success()->title('Google Search Console is connected')->body('Your search data will appear on the dashboard. The first numbers can take a few minutes.')->send();
        } else {
            Notification::make()->warning()->title('Google Search Console was not connected')->body(PortalSearchConsole::failureMessage((string) request()->query('reason'), $this->host))->persistent()->send();
        }

        $this->redirect(static::getUrl());
    }

    public function connectAction(): Action
    {
        return Action::make('connect')
            ->label('Connect Google Search Console')
            ->icon(Heroicon::OutlinedLink)
            ->size('lg')
            ->action(fn () => $this->startConnecting());
    }

    public function reconnectAction(): Action
    {
        return Action::make('reconnect')
            ->label('Reconnect')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(fn () => $this->startConnecting());
    }

    public function tryAnotherAction(): Action
    {
        return Action::make('tryAnother')
            ->label('Try another account')
            ->icon(Heroicon::OutlinedUserCircle)
            ->action(fn () => $this->startConnecting());
    }

    public function syncAction(): Action
    {
        return Action::make('sync')
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $this->authorizeManaging();

                $result = app(PortalSearchConsole::class)->sync();

                if (! $result['ok']) {
                    Notification::make()->warning()->title('Not refreshed')->body($result['error'])->send();

                    return;
                }

                Notification::make()->success()->title('Refreshing')->body('Gadya Media is asking Google for the latest numbers. They show up here and on the dashboard within a few minutes.')->send();
            });
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->outlined()
            ->requiresConfirmation()
            ->modalHeading('Disconnect Google Search Console?')
            ->modalDescription('Gadya Media lets go of your Google account and deletes the search numbers it kept. You can connect again any time.')
            ->modalSubmitActionLabel('Disconnect')
            ->action(function (): void {
                $this->authorizeManaging();

                $result = app(PortalSearchConsole::class)->disconnect();

                if (! $result['ok']) {
                    Notification::make()->warning()->title('Not disconnected')->body($result['error'])->send();

                    return;
                }

                Notification::make()->success()->title('Disconnected')->body('Google Search Console is no longer connected.')->send();
            });
    }

    /**
     * Ask the portal for Google's sign-in address and send the browser
     * there. A portal that says no, or cannot be reached, is a plain
     * notification: the page stays as it was.
     */
    private function startConnecting(): mixed
    {
        $this->authorizeManaging();

        if (! $this->canConnectHere()) {
            Notification::make()->warning()->title('Connect from the live site')->body('Google can only send people back to an https address, and this copy of the site is not on one. Open the admin on the live site to connect.')->send();

            return null;
        }

        $result = app(PortalSearchConsole::class)->connectUrl(static::getUrl(), auth()->user()?->email);

        if ($result['url'] === null) {
            Notification::make()->warning()->title('Could not start connecting')->body($result['error'])->persistent()->send();

            return null;
        }

        return $this->redirect($result['url']);
    }

    private function authorizeManaging(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Advanced: use your own Google service account')
                        ->description('Only if you would rather not connect through Gadya Media. What people searched for to find the site, from a service account you make in Google Cloud and add to the property as a user.')
                        ->collapsible()
                        ->collapsed(fn (): bool => app(PortalSearchConsole::class)->available())
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
