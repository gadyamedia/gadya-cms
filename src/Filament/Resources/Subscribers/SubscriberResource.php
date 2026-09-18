<?php

namespace Gadya\Cms\Filament\Resources\Subscribers;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Subscribers\Pages\ListSubscribers;
use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * The mailing list. Kept here so it belongs to the client rather than to
 * whichever service she is using this year, and exported in the columns
 * Mailchimp and every service that copied it expect.
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Mailing list';

    protected static ?string $modelLabel = 'subscriber';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('name')->maxLength(120),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->searchable()->placeholder('—')->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Subscriber::SUBSCRIBED ? 'On the list' : 'Left')
                    ->color(fn (string $state): string => $state === Subscriber::SUBSCRIBED ? 'success' : 'gray'),
                TextColumn::make('source')->label('Signed up on')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Subscriber::SUBSCRIBED => 'On the list',
                    Subscriber::UNSUBSCRIBED => 'Left',
                ]),
            ])
            ->headerActions([static::exportAction()])
            ->recordActions([
                Action::make('unsubscribe')
                    ->label('Take off the list')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->requiresConfirmation()
                    ->visible(fn (Subscriber $record): bool => $record->isSubscribed())
                    ->action(fn (Subscriber $record) => $record->unsubscribe()),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Everyone on the list, in the columns a mailing service expects, so
     * the file imports without anyone renaming a header.
     */
    protected static function exportAction(): Action
    {
        return Action::make('export')
            ->label('Download the list')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): StreamedResponse {
                $subscribers = static::getEloquentQuery()->subscribed()->orderBy('email')->get();

                return response()->streamDownload(function () use ($subscribers): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['Email Address', 'First Name', 'Last Name', 'Signed up', 'Source']);

                    foreach ($subscribers as $subscriber) {
                        $names = preg_split('/\s+/', trim((string) $subscriber->name), 2) ?: [''];

                        fputcsv($out, [
                            $subscriber->email,
                            $names[0] ?? '',
                            $names[1] ?? '',
                            $subscriber->created_at?->toDateString(),
                            $subscriber->source,
                        ]);
                    }

                    fclose($out);
                }, 'mailing-list-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
            });
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::ENQUIRIES)) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSubscribers::route('/')];
    }
}
