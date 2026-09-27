<?php

namespace Gadya\Cms\Filament\Resources\ChangeRequests;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\ChangeRequests\Pages\ListChangeRequests;
use Gadya\Cms\Models\ChangeRequest;
use Gadya\Cms\Portal\ChangeRequests;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Changes asked for in the Gadya Media portal and drafted by AI, waiting
 * for someone here to look. Publishing is the usual Publish button - the
 * change is in the draft like any other edit - so the only thing this
 * screen adds is the way to throw one away.
 */
class ChangeRequestResource extends Resource
{
    protected static ?string $model = ChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Requested changes';

    protected static ?string $modelLabel = 'requested change';

    protected static ?int $navigationSort = 7;

    public static function getNavigationBadge(): ?string
    {
        $waiting = rescue(fn (): int => static::getEloquentQuery()->drafted()->count(), 0, report: false);

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('instructions')->label('What was asked')->limit(120)->wrap(),
                TextColumn::make('page_title')->label('Page')->placeholder('—'),
                TextColumn::make('changes')
                    ->label('Changed')
                    ->state(fn (ChangeRequest $record): string => collect($record->changes ?? [])->map(fn (array $change): string => Str::headline(Str::afterLast($change['field'], '.')))->implode(', '))
                    ->wrap(),
                TextColumn::make('requested_by')->label('Asked by')->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ChangeRequest::STATUS_DRAFTED => 'In your draft',
                        ChangeRequest::STATUS_PUBLISHED => 'Live',
                        default => 'Discarded',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        ChangeRequest::STATUS_DRAFTED => 'warning',
                        ChangeRequest::STATUS_PUBLISHED => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('Asked')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (ChangeRequest $record): bool => $record->isDrafted() && filled($record->preview_url))
                    ->url(fn (ChangeRequest $record): ?string => $record->preview_url, shouldOpenInNewTab: true),
                static::discardAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Puts each field back as it was, unless someone has rewritten it
     * since - then theirs stays.
     */
    protected static function discardAction(): Action
    {
        return Action::make('discard')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (ChangeRequest $record): bool => $record->isDrafted())
            ->requiresConfirmation()
            ->modalHeading('Discard this change?')
            ->modalDescription('The words go back to what they said before, in your draft. The live site has not changed and will not.')
            ->action(function (ChangeRequest $record, ChangeRequests $requests): void {
                $requests->discard($record);

                Notification::make()->success()->title('Discarded')->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListChangeRequests::route('/')];
    }
}
