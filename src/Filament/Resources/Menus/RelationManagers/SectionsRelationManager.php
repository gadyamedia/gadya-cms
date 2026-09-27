<?php

namespace Gadya\Cms\Filament\Resources\Menus\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gadya\Cms\Filament\Resources\MenuItems\MenuItemResource;
use Gadya\Cms\Models\MenuSection;

/**
 * The headings of a menu, dragged into the order they are read in.
 */
class SectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    protected static ?string $title = 'Sections';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->placeholder('Bagels'),
            TextInput::make('availability')->label('When it is served')->maxLength(255)->placeholder('Until 11am'),
            Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->wrap(),
                TextColumn::make('availability')->label('Served')->placeholder('—'),
                TextColumn::make('items_count')->counts('items')->label('Items'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a section'),
            ])
            ->recordActions([
                Action::make('items')
                    ->label('Items')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->url(fn (MenuSection $record): string => MenuItemResource::getUrl('index', ['filters' => ['section' => ['value' => $record->getKey()]]])),
                EditAction::make(),
                DeleteAction::make()->modalDescription('The section and every item in it are deleted.'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }
}
