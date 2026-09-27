<?php

namespace Gadya\Cms\Filament\Resources\Menus\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;
use Gadya\Cms\Filament\Resources\Menus\MenuResource;
use Gadya\Cms\Models\Menu;

class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('items')
                ->label('Items & sold out')
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(fn (Menu $record): string => MenuItemResource::getUrl('index', ['filters' => ['menu' => ['value' => $record->getKey()]]])),
            DeleteAction::make()->modalDescription('The menu, its sections and every item on it are deleted.'),
        ];
    }
}
