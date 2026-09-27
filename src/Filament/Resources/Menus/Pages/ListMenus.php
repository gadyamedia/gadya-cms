<?php

namespace Gadya\Cms\Filament\Resources\Menus\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;
use Gadya\Cms\Filament\Resources\Menus\MenuResource;

class ListMenus extends ListRecords
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('items')
                ->label('Items & sold out')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(MenuItemResource::getUrl()),
            CreateAction::make()->label('Add a menu'),
        ];
    }
}
