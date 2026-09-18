<?php

namespace Gadya\Cms\Filament\Resources\Events\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Gadya\Cms\Filament\Resources\Events\EventResource;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
