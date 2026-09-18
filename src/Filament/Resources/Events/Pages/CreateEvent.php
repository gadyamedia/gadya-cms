<?php

namespace Gadya\Cms\Filament\Resources\Events\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Filament\Resources\Events\EventResource;
use Gadya\Cms\Support\SiteContext;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['site_id'] = app(SiteContext::class)->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
