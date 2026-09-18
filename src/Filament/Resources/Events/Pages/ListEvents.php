<?php

namespace Gadya\Cms\Filament\Resources\Events\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Events\EventResource;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    public function getTitle(): string
    {
        return (string) config('gadya-cms.events.title', 'What’s on');
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add an event')];
    }
}
