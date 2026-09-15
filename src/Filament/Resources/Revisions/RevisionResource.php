<?php

namespace Gadya\Cms\Filament\Resources\Revisions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Revisions\Pages\ListRevisions;
use Gadya\Cms\Models\Revision;
use Gadya\Cms\Services\RevertSiteContent;
use UnitEnum;

class RevisionResource extends Resource
{
    protected static ?string $model = Revision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'History';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('published_at')->label('Published')->dateTime()->sortable(),
                TextColumn::make('publisher.name')->label('By')->default('—'),
                TextColumn::make('label')->label('What changed')->default('—')->wrap(),
                TextColumn::make('pages')
                    ->label('Pages')
                    ->state(fn (Revision $record): int => count($record->snapshot['pages'] ?? [])),
            ])
            ->recordActions([
                static::restoreAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Restoring puts the chosen snapshot back as both the draft and the
     * live site, and is itself recorded as a revision so the history stays
     * truthful rather than rewriting itself.
     */
    protected static function restoreAction(): Action
    {
        return Action::make('restore')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->requiresConfirmation()
            ->modalHeading('Restore this version')
            ->modalDescription('The site goes back to how it looked at this point. Anything edited since is replaced.')
            ->action(function (Revision $record, RevertSiteContent $revert): void {
                $revert->handle($record, auth()->user());

                Notification::make()->success()->title('Version restored')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRevisions::route('/'),
        ];
    }
}
