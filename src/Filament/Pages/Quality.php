<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Ai\PortalBrain;
use Gadya\Cms\Models\Fix;
use Gadya\Cms\Quality\ApplyFix;
use Gadya\Cms\Quality\Failures;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What Google's check found wrong with the site, in words the client can
 * act on, and a button for each thing the CMS can put right itself.
 */
class Quality extends Page
{
    protected string $view = 'gadya-cms::filament.pages.quality';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Speed & accessibility';

    protected static ?string $title = 'Speed & accessibility';

    protected static ?int $navigationSort = 4;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('describePhotos')
                ->label('Describe the photos')
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => app(PortalBrain::class)->available())
                ->requiresConfirmation()
                ->modalHeading('Describe the photos that have none')
                ->modalDescription('Gadya CMS looks at each photo and writes one sentence saying what is in it, so screen readers and Google can read it. Nothing on the live site changes until you publish.')
                ->action(function (ApplyFix $fixer): void {
                    $result = $fixer->describePhotos();

                    $this->report('photo', $result);
                }),
            Action::make('describePages')
                ->label('Write the missing search snippets')
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => app(PortalBrain::class)->available())
                ->requiresConfirmation()
                ->modalHeading('Write the sentence under each page in search results')
                ->modalDescription('Gadya CMS reads each page and writes the sentence Google shows under its name. It goes into your draft, so you can change any of it before you publish.')
                ->action(function (ApplyFix $fixer): void {
                    $result = $fixer->describePages();

                    $this->report('page', $result);
                }),
        ];
    }

    /**
     * @param  array{done: int, left: int}  $result
     */
    private function report(string $noun, array $result): void
    {
        if ($result['done'] === 0) {
            Notification::make()
                ->warning()
                ->title('Nothing to do')
                ->body($result['left'] > 0 ? 'Gadya could not write anything just now. Try again in a few minutes.' : 'Every '.$noun.' already has one.')
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Gadya CMS wrote '.$result['done'].' '.str($noun)->plural($result['done']))
            ->body($result['left'] > 0
                ? $result['left'].' still to go. Read them below, change anything you would say differently, then publish.'
                : 'Read them below, change anything you would say differently, then publish.')
            ->send();
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getFailuresProperty(): Collection
    {
        return app(Failures::class)->all();
    }

    /** @return Collection<int, Fix> */
    public function getRecentFixesProperty(): Collection
    {
        return Fix::query()->latest()->limit(20)->get();
    }

    public function getWritesForUsProperty(): bool
    {
        return app(PortalBrain::class)->throughPortal();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
