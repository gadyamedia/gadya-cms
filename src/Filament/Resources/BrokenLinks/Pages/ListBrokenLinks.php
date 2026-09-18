<?php

namespace Gadya\Cms\Filament\Resources\BrokenLinks\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\BrokenLinks\BrokenLinkResource;

class ListBrokenLinks extends ListRecords
{
    protected static string $resource = BrokenLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
