<?php

namespace Gadya\Cms\Filament\Resources\BrokenLinks;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\Resources\BrokenLinks\Pages\ListBrokenLinks;
use Gadya\Cms\Models\BrokenLink;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Seo\LinkChecker;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Addresses that do not work, and the one-click way to fix the ones that
 * matter: send them somewhere that does.
 */
class BrokenLinkResource extends Resource
{
    protected static ?string $model = BrokenLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLinkSlash;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Broken links';

    protected static ?string $modelLabel = 'broken link';

    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $count = rescue(fn (): int => static::getEloquentQuery()->unresolved()->count(), 0, report: false);

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')->label('Address')->searchable()->wrap()->copyable(),
                TextColumn::make('kind')
                    ->label('How we know')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => BrokenLink::kindLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => $state === BrokenLink::LINKED ? 'danger' : 'warning'),
                TextColumn::make('found_on')->label('Linked from')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('referrer_host')->label('Came from')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hits')->label('Times')->numeric()->sortable(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->sortable(),
                TextColumn::make('resolved_at')
                    ->label('Fixed')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state ? 'Fixed' : 'Open')
                    ->color(fn ($state): string => $state ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('How we know')->options(BrokenLink::kindLabels()),
                Filter::make('unresolved')
                    ->label('Still broken')
                    ->query(fn (Builder $query): Builder => $query->unresolved())
                    ->default(),
            ])
            ->headerActions([
                Action::make('check')
                    ->label('Look for broken links')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->action(function (LinkChecker $checker): void {
                        $result = $checker->check();

                        Notification::make()
                            ->success()
                            ->title('Looked at '.$result['checked'].' links')
                            ->body($result['broken'] === 0 ? 'They all work.' : $result['broken'].' of them lead nowhere.')
                            ->send();
                    }),
            ])
            ->recordActions([
                static::redirectAction(),
                Action::make('resolve')
                    ->label('Mark as fixed')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (BrokenLink $record): bool => $record->resolved_at === null)
                    ->action(fn (BrokenLink $record) => $record->resolve()),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()])
            ->defaultSort('hits', 'desc');
    }

    /**
     * The fix for most of these: send the address somewhere that works.
     * Made here rather than on the redirects screen, because this is where
     * the client is standing when she notices.
     */
    protected static function redirectAction(): Action
    {
        return Action::make('redirect')
            ->label('Send it somewhere')
            ->icon(Heroicon::OutlinedArrowUturnRight)
            ->visible(fn (BrokenLink $record): bool => ! str_starts_with($record->url, 'http'))
            ->schema([
                TextInput::make('to_path')
                    ->label('Send visitors to')
                    ->required()
                    ->maxLength(500)
                    ->rule('regex:~^(/|https?://)~')
                    ->placeholder('/the-page-that-replaced-it'),
                Select::make('status_code')
                    ->label('Kind')
                    ->options([301 => 'Permanent', 302 => 'Temporary'])
                    ->default(301)
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, BrokenLink $record): void {
                Redirect::query()->updateOrCreate(
                    ['site_id' => app(SiteContext::class)->id(), 'from_path' => Redirect::normalise($record->url)],
                    ['to_path' => $data['to_path'], 'status_code' => (int) $data['status_code']],
                );

                $record->resolve();

                Notification::make()->success()->title('Forwarded')->body('Anyone who goes there now ends up in the right place.')->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
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
        return ['index' => ListBrokenLinks::route('/')];
    }
}
