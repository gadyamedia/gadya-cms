<?php

namespace Gadya\Cms\Filament\Resources\Pages\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\Resources\Pages\PageResource;

class ListPages extends ListRecords
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PublishChangesAction::make(),
            CreateAction::make(),
        ];
    }
}
