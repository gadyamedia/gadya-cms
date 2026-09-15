<?php

namespace Gadya\Cms\Filament\Resources\Redirects\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Gadya\Cms\Filament\Resources\Redirects\RedirectResource;
use Gadya\Cms\Support\SiteContext;

class ListRedirects extends ListRecords
{
    protected static string $resource = RedirectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add a redirect')
                ->mutateDataUsing(fn (array $data): array => [...$data, 'site_id' => app(SiteContext::class)->id()]),
        ];
    }
}
