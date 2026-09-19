<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Seo\SiteSetup;
use UnitEnum;

/**
 * Everything outside the site itself that decides whether Google and AI
 * assistants find it: the files they fetch, the sitemap handed to Search
 * Console, and the DNS records on every domain the business owns.
 */
class GetFound extends Page
{
    protected string $view = 'gadya-cms::filament.pages.get-found';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Get found';

    protected static ?string $title = 'Get found on Google and AI';

    protected static ?string $slug = 'get-found';

    protected static ?int $navigationSort = 5;

    /**
     * @return list<array{path: string, url: string, status: int, ok: bool, fix: string}>
     */
    public function getLiveChecksProperty(): array
    {
        return app(SiteSetup::class)->liveChecks();
    }

    /**
     * @return array<string, list<array{type: string, name: string, value: string, why: string, status: string, found: list<string>}>>
     */
    public function getDomainRecordsProperty(): array
    {
        $setup = app(SiteSetup::class);

        return collect($setup->domains())
            ->mapWithKeys(fn (string $domain): array => [$domain => $setup->records($domain)])
            ->all();
    }

    public function getSitemapUrlProperty(): string
    {
        return app(SiteSetup::class)->sitemapUrl();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkAgain')
                ->label('Check again')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (SiteSetup $setup): void {
                    $setup->forgetLiveChecks();

                    Notification::make()->success()->title('Checked again')->body('DNS changes can take up to a day to show everywhere.')->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
