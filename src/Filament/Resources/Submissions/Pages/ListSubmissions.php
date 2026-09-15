<?php

namespace Gadya\Cms\Filament\Resources\Submissions\Pages;

use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
