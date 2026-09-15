<?php

namespace Gadya\Cms\Filament\Resources\Pages\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Filament\Resources\Pages\PageResource;
use Gadya\Cms\Support\SiteContext;

class CreatePage extends CreateRecord
{
    protected static string $resource = PageResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['site_id'] = app(SiteContext::class)->id();
        $data['draft'] = [
            'description' => '',
            'sections' => [],
            ...(is_array($data['draft'] ?? null) ? $data['draft'] : []),
        ];

        return $data;
    }
}
