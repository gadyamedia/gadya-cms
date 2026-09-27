<?php

namespace Gadya\Cms\Filament\Resources\MenuItems\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;

class ListMenuItems extends ListRecords
{
    protected static string $resource = MenuItemResource::class;

    public function getTitle(): string
    {
        return 'Menu items';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add an item')];
    }
}
