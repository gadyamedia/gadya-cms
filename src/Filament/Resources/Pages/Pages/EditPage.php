<?php

namespace Gadya\Cms\Filament\Resources\Pages\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\Resources\Pages\PageResource;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PublishChangesAction::make(),
            DeleteAction::make(),
        ];
    }
}
