<?php

namespace Gadya\Cms\Filament\Resources\Posts\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Blog\ContentAudit;
use Gadya\Cms\Filament\Resources\Posts\PostResource;
use Gadya\Cms\Support\SiteContext;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['site_id'] = app(SiteContext::class)->id();
        $data['author_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->terms()->sync(PostResource::termIdsFrom($this->data));

        app(ContentAudit::class)->record($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
