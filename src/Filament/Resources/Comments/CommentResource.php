<?php

namespace Gadya\Cms\Filament\Resources\Comments;

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
use Gadya\Cms\Filament\Resources\Comments\Pages\ListComments;
use Gadya\Cms\Models\Comment;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Everything readers have written under the articles, waiting to be read.
 */
class CommentResource extends Resource
{
    protected static ?string $model = Comment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Comments';

    protected static ?string $modelLabel = 'comment';

    protected static ?int $navigationSort = 8;

    /*
     * Registered with the rest of the blog but out of sight until the site
     * turns comments on, so switching them on is a config change rather
     * than a panel that has to be rebuilt.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('gadya-cms.blog.comments.enabled', false);
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = rescue(fn (): int => static::getEloquentQuery()->pending()->count(), 0, report: false);

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_name')
                    ->label('From')
                    ->description(fn (Comment $record): ?string => $record->author_email)
                    ->searchable()
                    ->weight(fn (Comment $record): string => $record->status === Comment::PENDING ? 'bold' : 'normal'),
                TextColumn::make('body')->label('Said')->wrap()->searchable()->limit(140),
                TextColumn::make('post.title')->label('Under')->wrap()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Comment::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Comment::APPROVED => 'success',
                        Comment::SPAM => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')->label('Written')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Comment::statusLabels())->default(Comment::PENDING),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Show it')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Comment $record): bool => $record->status !== Comment::APPROVED)
                    ->action(fn (Comment $record) => $record->approve(auth()->id())),
                Action::make('spam')
                    ->label('Spam')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Comment $record): bool => $record->status !== Comment::SPAM)
                    ->action(fn (Comment $record) => $record->markSpam()),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('approveAll')
                    ->label('Show them')
                    ->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => $records->each(fn (Comment $comment) => $comment->approve(auth()->id())))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('spamAll')
                    ->label('Mark as spam')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->action(fn (Collection $records) => $records->each->markSpam())
                    ->deselectRecordsAfterCompletion(),
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return config('gadya-cms.blog.comments.enabled', false)
            && (auth()->user()?->can(Abilities::gate(Abilities::ARTICLES)) ?? false);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListComments::route('/')];
    }
}
