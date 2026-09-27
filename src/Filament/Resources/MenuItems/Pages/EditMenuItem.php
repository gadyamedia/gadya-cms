<?php

namespace Gadya\Cms\Filament\Resources\MenuItems\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;

class EditMenuItem extends EditRecord
{
    protected static string $resource = MenuItemResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
