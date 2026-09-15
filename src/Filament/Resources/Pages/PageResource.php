<?php

namespace Gadya\Cms\Filament\Resources\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Editor\PreviewLink;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Pages\Pages\CreatePage;
use Gadya\Cms\Filament\Resources\Pages\Pages\EditPage;
use Gadya\Cms\Filament\Resources\Pages\Pages\ListPages;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Gadya\Cms\Filament\Schemas\SeoSection;
use Gadya\Cms\Models\Page;
use Gadya\Cms\Services\ManagePages;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Page')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(70)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?Page $record): void {
                            if ($record === null && ($get('slug') ?? '') === '') {
                                $set('slug', Str::slug($state ?? ''));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Address')
                        ->required()
                        ->maxLength(60)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                        ->helperText(fn (?Page $record): string => $record === null
                            ? 'The part of the web address after the slash.'
                            : 'This page is published at '.static::publicPathFor($record))
                        ->rule(fn (): Closure => static::notReservedRule())
                        ->disabled(fn (?Page $record): bool => $record !== null)
                        ->dehydrated(fn (?Page $record): bool => $record === null)
                        ->unique(ignoreRecord: true),
                    Select::make('type')
                        ->options(fn (?Page $record): array => static::typeOptions($record))
                        ->required(),
                    Select::make('status')
                        ->options([
                            Page::STATUS_PUBLISHED => 'Visible',
                            Page::STATUS_ARCHIVED => 'Hidden',
                        ])
                        ->required(),
                ])
                ->columns(2),
            Section::make('Content')
                ->schema([
                    TextInput::make('draft.heading')->label('Heading')->maxLength(120),
                    TextInput::make('draft.cta')->label('Button text')->maxLength(60),
                    Textarea::make('draft.description')->label('Description')->rows(4)->columnSpanFull(),
                    MediaSelect::make('draft.hero_image', 'Main photo'),
                ])
                ->columns(2),
            Section::make('When it is visible')
                ->description('Leave both blank and the page is visible whenever it is not hidden.')
                ->schema([
                    DateTimePicker::make('draft.publish_at')
                        ->label('Show from')
                        ->seconds(false)
                        ->helperText('A date still to come schedules the page.'),
                    DateTimePicker::make('draft.unpublish_at')
                        ->label('Hide after')
                        ->seconds(false)
                        ->after('draft.publish_at'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(fn (?Page $record): bool => blank($record?->draft['publish_at'] ?? null) && blank($record?->draft['unpublish_at'] ?? null)),
            SeoSection::make('draft.seo.'),
            Section::make('Sections')
                ->description('The blocks that make up the body of the page, in the order they appear.')
                ->schema([
                    Repeater::make('draft.sections')
                        ->hiddenLabel()
                        ->default([])
                        ->schema([
                            TextInput::make('title')->maxLength(120),
                            Select::make('type')
                                ->options(fn (): array => static::sectionTypeOptions())
                                ->required()
                                ->live(),
                            TextInput::make('subtitle')->maxLength(160)->columnSpanFull(),
                            Textarea::make('text')->rows(3)->columnSpanFull(),
                            Repeater::make('items')
                                ->schema([
                                    TextInput::make('title')->maxLength(200),
                                    Textarea::make('text')->rows(2),
                                    MediaSelect::make('image', 'Photo'),
                                ])
                                ->columns(3)
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                ->visible(fn (Get $get): bool => $get('type') !== 'gallery')
                                ->columnSpanFull(),
                            Repeater::make('images')
                                ->simple(MediaSelect::make('image', 'Photo'))
                                ->visible(fn (Get $get): bool => $get('type') === 'gallery')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->collapsed()
                        ->cloneable()
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')
                    ->label('Address')
                    ->state(fn (Page $record): string => static::publicPathFor($record))
                    ->url(fn (Page $record): string => url(static::publicPathFor($record)))
                    ->openUrlInNewTab()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('status')
                    ->state(fn (Page $record): string => static::visibilityLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Visible' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Page::STATUS_PUBLISHED => 'Visible',
                    Page::STATUS_ARCHIVED => 'Hidden',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                static::renameAction(),
                static::editLiveAction(),
                static::previewLinkAction(),
                DeleteAction::make(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    /**
     * Renaming is not a plain column edit: the old address has to keep
     * working, so it goes through the service that also writes the
     * redirect and rewrites the navigation.
     */
    protected static function renameAction(): Action
    {
        return Action::make('rename')
            ->icon(Heroicon::OutlinedLink)
            ->modalDescription('The old address will keep working - visitors and search engines are redirected to the new one.')
            ->schema([
                TextInput::make('slug')
                    ->label('New address')
                    ->required()
                    ->maxLength(60)
                    ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/'),
            ])
            ->action(function (array $data, Page $record, ManagePages $managePages): void {
                try {
                    $managePages->rename($record->slug, $data['slug']);
                } catch (RuntimeException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Address changed')->send();
            });
    }

    /**
     * Opens the real page with the live editor switched on, which is where
     * the client does most of her work: the admin is for structure, the
     * page itself is for words and photos.
     */
    protected static function editLiveAction(): Action
    {
        return Action::make('editLive')
            ->label('Edit on the page')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->action(function (Page $record, EditingLock $lock): RedirectResponse {
                session([EditContext::SESSION_KEY => true]);

                $user = auth()->user();

                if ($user !== null) {
                    $lock->acquire($user);
                }

                return redirect()->to(url(static::publicPathFor($record)));
            });
    }

    /**
     * A link to the draft of this page for someone without an account,
     * good for a few days.
     */
    protected static function previewLinkAction(): Action
    {
        return Action::make('previewLink')
            ->label('Share a preview')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Share a preview of the draft')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->modalContent(fn (Page $record): View => view('gadya-cms::filament.preview-link', [
                'url' => app(PreviewLink::class)->for(static::publicPathFor($record)),
                'expires' => now()->addHours((int) config('gadya-cms.preview.expires_hours', 72))->format('D j M, g:ia'),
            ]));
    }

    public static function visibilityLabel(Page $page): string
    {
        $registry = app(PageRegistry::class);
        $draft = $page->draft ?? [];

        return match (true) {
            $page->isArchived() => 'Hidden',
            $registry->isScheduled($draft) => 'Scheduled',
            ! $registry->isWithinSchedule($draft) => 'Expired',
            default => 'Visible',
        };
    }

    /**
     * Slugs that collide with a real route can never be taken, however the
     * form is submitted.
     */
    protected static function notReservedRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && app(PageRegistry::class)->isReserved($value)) {
                $fail("The address [{$value}] is reserved by the site.");
            }
        };
    }

    /**
     * The address a visitor actually uses, which for a location is the
     * location route rather than the page's own slug. Showing the slug
     * alone meant the panel never displayed the address the client sees in
     * her browser.
     */
    protected static function publicPathFor(Page $page): string
    {
        $document = once(fn (): array => app(SiteContentRepository::class)->draft());

        return app(PageRegistry::class)->publicPathFor($page->slug, $document);
    }

    /**
     * The types a client may choose when creating a page, plus every type
     * already in use on the site.
     *
     * Listing only the creatable ones meant a page built from a
     * special-cased template - a location, the contact page - could not
     * be saved at all: its own type was missing from its own select, and
     * saving silently rewrote it to the first option. Offering the types in
     * use as well means a page whose type was changed that way can be put
     * back, rather than being stuck.
     *
     * @return array<string, string>
     */
    protected static function typeOptions(?Page $record = null): array
    {
        $types = app(PageRegistry::class)->creatableTypes();

        $inUse = Page::query()->distinct()->pluck('type')->filter(fn ($type): bool => is_string($type))->all();

        foreach ([...$inUse, $record?->type] as $type) {
            if (is_string($type) && $type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        sort($types);

        return array_combine($types, array_map(Str::headline(...), $types));
    }

    /**
     * @return array<string, string>
     */
    protected static function sectionTypeOptions(): array
    {
        $types = app(PageRegistry::class)->sectionTypes();

        return array_combine($types, array_map(Str::headline(...), $types));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
