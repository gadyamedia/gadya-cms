<?php

namespace Gadya\Cms\Filament\Resources\Posts\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Pages\ArticleGenerator;
use Gadya\Cms\Filament\Resources\Posts\PostResource;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            GadyaCmsPlugin::get()->hasAi()
                ? Action::make('writeWithAi')
                    ->label('Write with AI')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('info')
                    ->url(fn (): string => ArticleGenerator::getUrl())
                : null,
            CreateAction::make()->label('New article'),
        ]));
    }
}
