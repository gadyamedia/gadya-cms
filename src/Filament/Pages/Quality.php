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
use Gadya\Cms\Quality\AccessibilityRecord;
use Gadya\Cms\Quality\ApplyFix;
use Gadya\Cms\Quality\Drift;
use Gadya\Cms\Quality\Failures;
use Gadya\Cms\Quality\Visibility;
use Gadya\Cms\Transfer\Takeout;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
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
            Action::make('takeout')
                ->label('Download everything')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Download everything on this website')
                ->modalDescription('One zip with every word, photograph, article and enquiry - yours to keep, and readable without us. It takes a moment to pack.')
                ->modalSubmitActionLabel('Pack it up')
                ->action(function (Takeout $takeout): ?BinaryFileResponse {
                    try {
                        $path = $takeout->write(storage_path('app/takeout/'.Str::slug((string) config('app.name')).'-'.now()->format('Y-m-d').'.zip'));
                    } catch (Throwable $exception) {
                        Notification::make()->danger()->title('Could not pack it up')->body($exception->getMessage())->send();

                        return null;
                    }

                    return response()->download($path)->deleteFileAfterSend();
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

    /** @return array<string, mixed> */
    public function getAccessibilityProperty(): array
    {
        $record = app(AccessibilityRecord::class);

        return [
            'exists' => $record->exists(),
            'score' => $record->score(),
            'pages' => $record->pagesChecked(),
            'outstanding' => $record->outstanding()->count(),
            'remediated' => $record->remediated()->count(),
            'since' => $record->since(),
            'url' => config('gadya-cms.accessibility.statement', true)
                ? url('/'.trim((string) config('gadya-cms.accessibility.path', 'accessibility-statement'), '/'))
                : null,
        ];
    }

    /** @return Collection<int, array<string, string>> */
    public function getDriftProperty(): Collection
    {
        return app(Drift::class)->findings();
    }

    /** @return array<string, mixed>|null */
    public function getVisibilityProperty(): ?array
    {
        return app(Visibility::class)->latest();
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
