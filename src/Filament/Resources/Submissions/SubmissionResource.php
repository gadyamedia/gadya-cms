<?php

namespace Gadya\Cms\Filament\Resources\Submissions;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Submissions\Pages\ListSubmissions;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Models\FormSubmission;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Everything visitors have sent through the site's forms, in one inbox.
 */
class SubmissionResource extends Resource
{
    protected static ?string $model = FormSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Enquiries';

    protected static ?string $modelLabel = 'enquiry';

    protected static ?string $pluralModelLabel = 'enquiries';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $unread = rescue(fn (): int => static::getEloquentQuery()->unread()->count(), 0, report: false);

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sender')
                    ->label('From')
                    ->state(fn (FormSubmission $record): string => $record->sender())
                    ->weight(fn (FormSubmission $record): string => $record->status === FormSubmission::STATUS_NEW ? 'bold' : 'normal')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('data', 'like', "%{$search}%")),
                TextColumn::make('form')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => FormDefinition::labels()[$state] ?? Str::headline($state))
                    ->color('gray'),
                TextColumn::make('summary')
                    ->label('Message')
                    ->state(fn (FormSubmission $record): string => Str::limit((string) collect($record->data)->filter(fn ($value): bool => is_string($value))->sortByDesc(fn (string $value): int => strlen($value))->first(), 80))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Str::headline($state))
                    ->color(fn (string $state): string => match ($state) {
                        FormSubmission::STATUS_NEW => 'primary',
                        FormSubmission::STATUS_ARCHIVED => 'gray',
                        default => 'success',
                    }),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('form')->options(fn (): array => FormDefinition::labels()),
                SelectFilter::make('status')->options([
                    FormSubmission::STATUS_NEW => 'New',
                    FormSubmission::STATUS_READ => 'Read',
                    FormSubmission::STATUS_ARCHIVED => 'Archived',
                ]),
            ])
            ->recordActions([
                static::openAction(),
                static::archiveAction(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                static::exportAction(),
                BulkAction::make('markRead')
                    ->label('Mark as read')
                    ->icon(Heroicon::OutlinedEnvelopeOpen)
                    ->action(fn (Collection $records) => $records->each->markRead())
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make(),
            ])
            ->recordAction('open')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Opening an enquiry is what marks it read - not a separate button
     * the client would have to remember.
     */
    protected static function openAction(): Action
    {
        return Action::make('open')
            ->label('Open')
            ->icon(Heroicon::OutlinedEye)
            ->modalHeading(fn (FormSubmission $record): string => (FormDefinition::labels()[$record->form] ?? Str::headline($record->form)).' from '.$record->sender())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->mountUsing(fn (FormSubmission $record) => $record->markRead())
            ->modalContent(fn (FormSubmission $record): View => view('gadya-cms::filament.submissions.detail', ['submission' => $record]));
    }

    protected static function archiveAction(): Action
    {
        return Action::make('archive')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->visible(fn (FormSubmission $record): bool => $record->status !== FormSubmission::STATUS_ARCHIVED)
            ->action(fn (FormSubmission $record) => $record->update(['status' => FormSubmission::STATUS_ARCHIVED]));
    }

    /**
     * Everything currently listed, as a spreadsheet. Streamed, so a long
     * history never has to fit in memory.
     */
    protected static function exportAction(): BulkAction
    {
        return BulkAction::make('export')
            ->label('Download as CSV')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (Collection $records): StreamedResponse {
                $fields = $records->flatMap(fn (FormSubmission $record): array => array_keys($record->data ?? []))->unique()->values()->all();

                return response()->streamDownload(function () use ($records, $fields): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['Received', 'Form', 'Status', 'Page', 'Country', ...array_map(Str::headline(...), $fields)]);

                    foreach ($records as $record) {
                        fputcsv($out, [
                            $record->created_at->toDateTimeString(),
                            $record->form,
                            $record->status,
                            $record->path,
                            $record->country,
                            ...array_map(fn (string $field): string => (string) (is_array($record->data[$field] ?? null) ? implode(', ', $record->data[$field]) : ($record->data[$field] ?? '')), $fields),
                        ]);
                    }

                    fclose($out);
                }, 'enquiries-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::ENQUIRIES)) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSubmissions::route('/')];
    }
}
