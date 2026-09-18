<?php

namespace Gadya\Cms\Filament\Resources\Events;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Events\Pages\CreateEvent;
use Gadya\Cms\Filament\Resources\Events\Pages\EditEvent;
use Gadya\Cms\Filament\Resources\Events\Pages\ListEvents;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Gadya\Cms\Models\Event;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Open days, camps, classes - anything whose point is the date it is on.
 */
class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'What’s on';

    protected static ?string $modelLabel = 'event';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $upcoming = rescue(fn (): int => static::getEloquentQuery()->upcoming()->count(), 0, report: false);

        return $upcoming > 0 ? (string) $upcoming : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What and when')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, ?string $state, ?Event $record): void {
                            if ($record === null) {
                                $set('slug', Str::slug((string) $state));
                            }
                        })
                        ->columnSpanFull(),
                    TextInput::make('slug')
                        ->label('Address')
                        ->required()
                        ->maxLength(120)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                        ->unique(ignoreRecord: true)
                        ->prefix(rtrim((string) config('app.url'), '/').'/'.trim((string) config('gadya-cms.events.prefix', 'events'), '/').'/')
                        ->columnSpanFull(),
                    DateTimePicker::make('starts_at')->label('Starts')->seconds(false)->required(),
                    DateTimePicker::make('ends_at')
                        ->label('Ends')
                        ->seconds(false)
                        ->after('starts_at')
                        ->helperText('Leave blank for something with no set finish.'),
                    Toggle::make('all_day')
                        ->label('All day')
                        ->helperText('The times are hidden and only the dates are shown.')
                        ->live(),
                    Select::make('status')
                        ->options([
                            Event::STATUS_DRAFT => 'Draft',
                            Event::STATUS_PUBLISHED => 'Published',
                        ])
                        ->default(Event::STATUS_DRAFT)
                        ->required()
                        ->native(false),
                ])
                ->columns(2),
            Section::make('Where and how much')
                ->schema([
                    TextInput::make('location')->maxLength(255)->placeholder('12 Evergreen Terrace, Springfield'),
                    TextInput::make('price')->maxLength(80)->placeholder('£8 a child, adults free'),
                    TextInput::make('booking_url')
                        ->label('Booking link')
                        ->url()
                        ->maxLength(500)
                        ->helperText('Where the Book a place button goes.')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),
            Section::make('Details')
                ->schema([
                    Textarea::make('summary')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('One or two lines for the list and for link previews.')
                        ->columnSpanFull(),
                    RichEditor::make('body')
                        ->label('Everything else')
                        ->toolbarButtons([
                            ['bold', 'italic', 'link'],
                            ['h2', 'h3'],
                            ['bulletList', 'orderedList'],
                            ['undo', 'redo'],
                        ])
                        ->columnSpanFull(),
                    MediaSelect::make('image', 'Photo'),
                    TextInput::make('hero_alt')->label('Describe the photo')->maxLength(140),
                ])
                ->columns(2)
                ->collapsible(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('starts_at')
                    ->label('When')
                    ->state(fn (Event $record): string => $record->when())
                    ->sortable()
                    ->wrap(),
                TextColumn::make('location')->placeholder('—')->toggleable()->wrap(),
                TextColumn::make('status')
                    ->state(fn (Event $record): string => match (true) {
                        ! $record->isLive() => 'Draft',
                        $record->hasFinished() => 'Been and gone',
                        default => 'Coming up',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Coming up' => 'success',
                        'Been and gone' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Event::STATUS_DRAFT => 'Draft',
                    Event::STATUS_PUBLISHED => 'Published',
                ]),
                Filter::make('upcoming')
                    ->label('Still to come')
                    ->query(fn (Builder $query): Builder => $query->upcoming())
                    ->default(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Event $record): string => url($record->publicPath()))
                    ->openUrlInNewTab()
                    ->visible(fn (Event $record): bool => $record->isLive()),
                DeleteAction::make(),
            ])
            ->defaultSort('starts_at');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
