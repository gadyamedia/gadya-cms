<?php

namespace Gadya\Cms\Filament\Resources\Activity;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Activity\Activity;
use Gadya\Cms\Filament\Resources\Activity\Pages\ListActivity;
use Gadya\Cms\Models\AuditLog;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Who changed what, and when - the answer to every "I did not touch it".
 */
class ActivityResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'change';

    protected static ?string $pluralModelLabel = 'activity';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->since()->tooltip(fn (AuditLog $record): string => $record->created_at->format('D j M Y, g:ia'))->sortable(),
                TextColumn::make('user.name')->label('Who')->placeholder('The site itself')->searchable(),
                TextColumn::make('event')
                    ->label('Did what')
                    ->formatStateUsing(fn (string $state): string => Activity::describe($state))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('subject')->label('To')->placeholder('—')->searchable()->wrap(),
                TextColumn::make('after')
                    ->label('Changed')
                    ->state(fn (AuditLog $record): string => collect($record->after ?? [])->keys()->map(fn (string $key): string => str_replace('_', ' ', $key))->implode(', ') ?: '—')
                    ->toggleable()
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('What happened')
                    ->options(fn (): array => static::getEloquentQuery()
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->map(fn (string $event): string => Activity::describe($event))
                        ->all()),
                SelectFilter::make('user_id')
                    ->label('Who')
                    ->relationship('user', 'name'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user')->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListActivity::route('/')];
    }
}
