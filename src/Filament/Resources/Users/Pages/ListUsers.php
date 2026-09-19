<?php

namespace Gadya\Cms\Filament\Resources\Users\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Users\UserResource;
use Illuminate\Contracts\View\View;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * The sign-in details, shown once straight after a password is set,
     * ready to copy into a message. Nothing is emailed.
     */
    public function credentialsAction(): Action
    {
        return Action::make('credentials')
            ->modalHeading('Their sign-in details')
            ->modalDescription('Copy this and send it to them however you like. The password is not shown again.')
            ->modalContent(fn (array $arguments): View => view('gadya-cms::filament.team.credentials', ['text' => (string) ($arguments['text'] ?? '')]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }
}
