<?php

namespace Gadya\Cms\Filament\Resources\Comments\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Comments\CommentResource;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
