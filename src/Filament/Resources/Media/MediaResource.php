<?php

namespace Gadya\Cms\Filament\Resources\Media;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Ai\Agents\AltTextWriter;
use Gadya\Cms\Ai\AiSettings;
use Gadya\Cms\Ai\Prompter;
use Gadya\Cms\Content\MediaUsage;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Media\Pages\ListMedia;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Services\StoreMediaUpload;
use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Throwable;
use UnitEnum;

class MediaResource extends Resource
{
    protected static ?string $model = Media::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Photos';

    protected static ?string $recordTitleAttribute = 'original_name';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('alt_text')
                ->label('Description for screen readers')
                ->maxLength(255)
                ->helperText('What the photo shows, for visitors who cannot see it.'),
            Select::make('focus')
                ->label('Keep this part in view')
                ->options(fn (): array => array_map(fn (array $preset): string => $preset['label'], Media::focusPresets()))
                ->default('centre')
                ->native(false)
                ->helperText('When a page crops the photo to fit, this part stays in the middle.'),
            static::folderInput(),
            TagsInput::make('tags')
                ->placeholder('Add a tag')
                ->helperText('Anything that helps find it again: an event, a room, a season.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('filename')
                    ->label('Photo')
                    ->getStateUsing(fn (Media $record): string => app(SiteImage::class)->thumbnailUrl($record->filename))
                    ->square(),
                TextColumn::make('original_name')->label('Name')->searchable()->sortable(),
                TextColumn::make('alt_text')->label('Description')->toggleable()->wrap(),
                TextColumn::make('folder')->badge()->color('gray')->placeholder('—')->sortable()->toggleable(),
                TextColumn::make('tags')->badge()->separator(',')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Media::STATUS_READY => 'success',
                        Media::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('usage')
                    ->label('Used on')
                    ->state(fn (Media $record): string => implode(', ', app(MediaUsage::class)->pagesUsing($record->filename)) ?: '—')
                    ->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('folder')->options(fn (): array => array_combine(Media::folders(), Media::folders())),
                SelectFilter::make('status')->options([
                    Media::STATUS_READY => 'Ready',
                    Media::STATUS_PROCESSING => 'Processing',
                    Media::STATUS_FAILED => 'Failed',
                ]),
            ])
            ->headerActions([
                static::uploadAction(),
            ])
            ->recordActions([
                static::describeWithAiAction(),
                EditAction::make()
                    ->label('Describe')
                    ->fillForm(fn (Media $record): array => [...$record->attributesToArray(), 'focus' => $record->focusPreset()])
                    ->using(function (Media $record, array $data): Media {
                        $preset = Media::focusPresets()[$data['focus'] ?? 'centre'] ?? Media::focusPresets()['centre'];

                        $record->update([
                            ...$data,
                            'focal_x' => $preset['x'],
                            'focal_y' => $preset['y'],
                        ]);

                        return $record;
                    }),
                static::deleteAction(),
            ])
            ->toolbarActions([
                static::moveAction(),
                static::deleteBulkAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    protected static function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Upload photos')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->schema([
                FileUpload::make('uploads')
                    ->label('Photos')
                    ->image()
                    ->multiple()
                    ->acceptedFileTypes(fn (): array => array_map(
                        fn (string $extension): string => 'image/'.($extension === 'jpg' ? 'jpeg' : $extension),
                        app(ImageCapabilities::class)->acceptedExtensions(),
                    ))
                    ->maxSize((int) config('gadya-cms.media.max_kilobytes', 15360))
                    ->storeFiles(false)
                    ->required(),
                static::folderInput(),
            ])
            ->action(function (array $data, StoreMediaUpload $store): void {
                foreach ($data['uploads'] as $upload) {
                    $store->handle($upload, auth()->id(), $data['folder'] ?? null);
                }

                Notification::make()
                    ->success()
                    ->title('Uploaded')
                    ->body('Your photos are being prepared and will appear here in a moment.')
                    ->send();
            });
    }

    /**
     * The first draft of a photo's description, written by looking at it.
     * A description a person then corrects is worth more than the empty
     * box it would otherwise have been.
     */
    protected static function describeWithAiAction(): Action
    {
        return Action::make('describeWithAi')
            ->label('Describe with AI')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (Media $record): bool => GadyaCmsPlugin::get()->hasAi()
                && $record->isReady()
                && app(AiSettings::class)->isConfigured())
            ->action(function (Media $record, Prompter $prompter): void {
                try {
                    $contents = Storage::disk($record->disk)->get($record->thumbnail_path ?: $record->path);

                    $description = $prompter->prompt(
                        app(AltTextWriter::class),
                        'Write the alt text for this photograph.',
                        [Image::fromBase64(base64_encode((string) $contents), $record->mime_type ?: 'image/webp')],
                    );
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('Could not describe it')->body(mb_substr($exception->getMessage(), 0, 300))->send();

                    return;
                }

                $record->update(['alt_text' => $description['decorative'] ? '' : (string) $description['alt']]);

                Notification::make()
                    ->success()
                    ->title($description['decorative'] ? 'Left blank on purpose' : 'Described')
                    ->body($description['decorative']
                        ? 'It reads as decoration, so its description is empty - which is the right answer for a divider or a texture.'
                        : $description['alt'])
                    ->send();
            });
    }

    protected static function folderInput(): TextInput
    {
        return TextInput::make('folder')
            ->maxLength(80)
            ->datalist(fn (): array => Media::folders())
            ->placeholder('No folder')
            ->helperText('Type a new folder name or pick one you already use.');
    }

    protected static function moveAction(): BulkAction
    {
        return BulkAction::make('move')
            ->label('Move to folder')
            ->icon(Heroicon::OutlinedFolder)
            ->schema([static::folderInput()])
            ->action(function (Collection $records, array $data): void {
                $records->each->update(['folder' => $data['folder'] ?: null]);

                Notification::make()->success()->title('Moved')->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Deleting several at once keeps the same guard as deleting one: a
     * photo still on a page or an article is skipped and named, rather
     * than the whole batch failing or a hole appearing on the site.
     */
    protected static function deleteBulkAction(): BulkAction
    {
        return BulkAction::make('delete')
            ->label('Delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Collection $records, MediaUsage $usage): void {
                $kept = [];
                $deleted = 0;

                foreach ($records as $record) {
                    if ($usage->pagesUsing($record->filename) !== []) {
                        $kept[] = $record->original_name;

                        continue;
                    }

                    if (! $record->is_legacy) {
                        Storage::disk($record->disk)->delete(array_filter([$record->path, $record->thumbnail_path]));
                    }

                    $record->delete();
                    $deleted++;
                }

                if ($kept !== []) {
                    Notification::make()->warning()->title('Some photos are still in use')->body('Kept: '.implode(', ', $kept))->persistent()->send();
                }

                if ($deleted > 0) {
                    Notification::make()->success()->title($deleted.' '.Str::plural('photo', $deleted).' deleted')->send();
                }
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * A photo that is still on a page - draft or live - is never deletable:
     * removing it would leave a hole on the site until the next publish.
     */
    protected static function deleteAction(): Action
    {
        return Action::make('delete')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (Media $record, MediaUsage $usage): void {
                $pages = $usage->pagesUsing($record->filename);

                if ($pages !== []) {
                    Notification::make()
                        ->danger()
                        ->title('That photo is still in use')
                        ->body('It appears on: '.implode(', ', $pages))
                        ->send();

                    return;
                }

                if (! $record->is_legacy) {
                    Storage::disk($record->disk)->delete(array_filter([$record->path, $record->thumbnail_path]));
                }

                $record->delete();

                Notification::make()->success()->title('Photo deleted')->send();
            });
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::PHOTOS)) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
        ];
    }
}
