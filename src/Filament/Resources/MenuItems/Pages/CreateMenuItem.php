<?php

namespace Gadya\Cms\Filament\Resources\MenuItems\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;

class CreateMenuItem extends CreateRecord
{
    protected static string $resource = MenuItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
