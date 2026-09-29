<?php

namespace Gadya\Cms\Filament\Resources\Forms\Pages;

use Filament\Resources\Pages\CreateRecord;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;

class CreateForm extends CreateRecord
{
    protected static string $resource = FormResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = SavesForms::prepare($data, $this);

        return [
            ...$data,
            'site_id' => app(SiteContext::class)->id(),
            'created_by' => auth()->id(),
            'published_at' => ($data['status'] ?? null) === Form::STATUS_PUBLISHED ? now() : null,
        ];
    }

    protected function afterCreate(): void
    {
        /** @var Form $form */
        $form = $this->record;

        $form->recordVersion(auth()->id());
    }
}
