<?php

namespace Gadya\Cms\Filament\Resources\Subscribers\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Subscribers\SubscriberResource;
use Gadya\Cms\Support\SiteContext;

class ListSubscribers extends ListRecords
{
    protected static string $resource = SubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add someone')
                ->mutateDataUsing(fn (array $data): array => [...$data, 'site_id' => app(SiteContext::class)->id()]),
        ];
    }
}
