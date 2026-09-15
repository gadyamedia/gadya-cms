<?php

namespace Gadya\Cms\Filament\Resources\Users\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Users\UserResource;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
