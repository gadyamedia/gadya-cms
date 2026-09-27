<?php

namespace Gadya\Cms\Filament\Resources\ChangeRequests\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\ChangeRequests\ChangeRequestResource;

class ListChangeRequests extends ListRecords
{
    protected static string $resource = ChangeRequestResource::class;
}
