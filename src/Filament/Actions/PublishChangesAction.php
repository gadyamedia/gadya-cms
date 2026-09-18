<?php

namespace Gadya\Cms\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Services\PublishSiteContent;
use Gadya\Cms\Services\SchedulePublish;
use Illuminate\Support\Carbon;

/**
 * Makes every pending change live in one step. Nothing the client does in
 * the admin or on the page touches the public site until this runs, which
 * is what lets her work on the site in front of real visitors.
 */
class PublishChangesAction
{
    public static function make(string $name = 'publishChanges'): Action
    {
        return Action::make($name)
            ->label('Publish changes')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->can(Abilities::gate(Abilities::PUBLISH)) ?? false)
            ->modalHeading('Publish changes')
            ->modalDescription('Everything you have edited becomes visible to visitors. Leave the time blank to do it now.')
            ->modalSubmitActionLabel('Publish')
            ->fillForm(fn (SchedulePublish $schedule): array => ['at' => $schedule->at()])
            ->schema([
                DateTimePicker::make('at')
                    ->label('When')
                    ->seconds(false)
                    ->minDate(now())
                    ->helperText('Blank means now. A time in the future holds it until then, and you can change or cancel it here.'),
            ])
            ->action(function (array $data, PublishSiteContent $publish, EditingLock $lock, SchedulePublish $schedule): void {
                $user = auth()->user();
                $holder = $lock->holder();

                if ($holder !== null && $user !== null && ! $lock->isHeldBy($user)) {
                    Notification::make()
                        ->warning()
                        ->title("{$holder} is editing right now")
                        ->body('Wait until they finish before publishing.')
                        ->send();

                    return;
                }

                $at = filled($data['at'] ?? null) ? Carbon::parse((string) $data['at']) : null;

                if ($at !== null && $at->isFuture()) {
                    $schedule->schedule($at);

                    Notification::make()
                        ->success()
                        ->title('Held until '.$at->format('l j F, g:ia'))
                        ->body('Everything in your draft goes live then. Publish again to change or cancel it.')
                        ->send();

                    return;
                }

                if ($schedule->isPending()) {
                    $schedule->cancel();
                }

                $publish->handle($user);

                Notification::make()->success()->title('Your changes are now live')->send();
            });
    }
}
