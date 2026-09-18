<?php

namespace Gadya\Cms\Filament\Resources\Activity\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Activity\ActivityResource;

class ListActivity extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
