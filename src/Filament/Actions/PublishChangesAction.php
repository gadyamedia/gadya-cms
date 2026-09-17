<?php

namespace Gadya\Cms\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Services\PublishSiteContent;

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
            ->requiresConfirmation()
            ->modalHeading('Publish changes')
            ->modalDescription('Everything you have edited becomes visible to visitors straight away.')
            ->action(function (PublishSiteContent $publish, EditingLock $lock): void {
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

                $publish->handle($user);

                Notification::make()->success()->title('Your changes are now live')->send();
            });
    }
}
