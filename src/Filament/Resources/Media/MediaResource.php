<?php

namespace Gadya\Cms\Filament\Resources\Media;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Content\MediaUsage;
use Gadya\Cms\Content\SiteImage;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Media\Pages\ListMedia;
use Gadya\Cms\Models\Media;
use Gadya\Cms\Services\StoreMediaUpload;
use Gadya\Cms\Support\ImageCapabilities;
use Illuminate\Support\Facades\Storage;
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
                EditAction::make()->label('Describe'),
                static::deleteAction(),
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
            ])
            ->action(function (array $data, StoreMediaUpload $store): void {
                foreach ($data['uploads'] as $upload) {
                    $store->handle($upload, auth()->id());
                }

                Notification::make()
                    ->success()
                    ->title('Uploaded')
                    ->body('Your photos are being prepared and will appear here in a moment.')
                    ->send();
            });
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

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
        ];
    }
}
