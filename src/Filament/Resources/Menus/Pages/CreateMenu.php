<?php

namespace Gadya\Cms\Filament\Resources\Menus\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Filament\Resources\Menus\MenuResource;
use Gadya\Cms\Models\Menu;
use Gadya\Cms\Support\SiteContext;

class CreateMenu extends CreateRecord
{
    protected static string $resource = MenuResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $siteId = app(SiteContext::class)->id();

        $data['site_id'] = $siteId;
        $data['sort_order'] = (int) Menu::query()->where('site_id', $siteId)->max('sort_order') + 1;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
