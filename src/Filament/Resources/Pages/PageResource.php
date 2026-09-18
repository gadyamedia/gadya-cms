<?php

namespace Gadya\Cms\Filament\Resources\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PageTypes;
use Gadya\Cms\Content\SiteBlocks;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
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
                        /*
                         * A trashed page still holds its address, but taking
                         * it again is how someone recreates a page they
                         * deleted; the row is restored and overwritten.
                         */
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNull('deleted_at')),
                    Select::make('type')
                        ->options(fn (?Page $record): array => static::typeOptions($record))
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('status')
                        ->options([
                            Page::STATUS_PUBLISHED => 'Visible',
                            Page::STATUS_ARCHIVED => 'Hidden',
                        ])
                        ->required(),
                ])
                ->columns(2),
            Section::make('Content')
                ->schema(fn (Get $get): array => static::contentFields($get('type')))
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
                ->key('sections')
                ->description('The blocks that make up the body of the page, in the order they appear.')
                ->headerActions([static::insertBlockAction()])
                ->schema([
                    Repeater::make('draft.sections')
                        ->hiddenLabel()
                        ->default([])
                        ->extraItemActions([static::saveAsBlockAction()])
                        ->schema([
                            TextInput::make('title')->maxLength(120),
                            Select::make('type')
                                ->options(fn (Get $get): array => static::sectionTypeOptions($get('../../type')))
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
                SelectFilter::make('type')->options(fn (): array => static::typeOptions()),
                TrashedFilter::make()->label('Trash'),
            ])
            ->recordActions([
                EditAction::make(),
                static::duplicateAction(),
                static::renameAction(),
                static::editLiveAction(),
                static::previewLinkAction(),
                DeleteAction::make()
                    ->modalDescription('The page goes to the trash and off the site. You can put it back from the Trash filter for '.(int) config('gadya-cms.trash.keep_days', 30).' days.')
                    ->before(fn (Page $record) => $record->forceFill(['deleted_by' => auth()->id()])->saveQuietly()),
                RestoreAction::make()->using(function (Page $record): bool {
                    $record->restoreToDraft();

                    return true;
                }),
                ForceDeleteAction::make()->label('Delete for good'),
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
     * The fields at the top of every page, as the application configured
     * them. A site whose pages carry different fields lists them in
     * `gadya-cms.pages.content_fields` rather than replacing this screen.
     *
     * @return list<Component>
     */
    protected static function contentFields(?string $type = null): array
    {
        $fields = [];

        foreach (app(PageTypes::class)->fieldsFor($type) as $name => $field) {
            if (! is_array($field) || ! is_string($name)) {
                continue;
            }

            $label = (string) ($field['label'] ?? Str::headline($name));
            $path = 'draft.'.$name;

            $fields[] = match ($field['type'] ?? 'text') {
                'textarea' => Textarea::make($path)->label($label)->rows((int) ($field['rows'] ?? 4))->maxLength((int) ($field['max'] ?? 2000))->columnSpanFull(),
                'image' => MediaSelect::make($path, $label),
                default => TextInput::make($path)->label($label)->maxLength((int) ($field['max'] ?? 120)),
            };
        }

        return $fields;
    }

    /**
     * Keep this section to use on another page. A copy is saved, not a
     * link: a client who later changes it here almost never means to
     * change it everywhere it was used.
     */
    protected static function saveAsBlockAction(): Action
    {
        return Action::make('saveAsBlock')
            ->label('Save as a block')
            ->icon(Heroicon::OutlinedBookmarkSquare)
            ->schema([
                TextInput::make('label')
                    ->label('Call it')
                    ->required()
                    ->maxLength(80)
                    ->helperText('What you will look for when you want it again.'),
            ])
            ->action(function (array $arguments, array $data, Repeater $component, SiteBlocks $blocks): void {
                $section = $component->getItemState($arguments['item']);

                if (! is_array($section) || $section === []) {
                    Notification::make()->warning()->title('Nothing to save yet')->body('Fill the section in first.')->send();

                    return;
                }

                $blocks->save($data['label'], $section);

                Notification::make()->success()->title('Saved as a block')->body('Insert it on any page from Add a saved block.')->send();
            });
    }

    /**
     * Drop a copy of a saved block at the end of this page's sections.
     */
    protected static function insertBlockAction(): Action
    {
        return Action::make('insertBlock')
            ->label('Add a saved block')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->visible(fn (): bool => app(SiteBlocks::class)->all() !== [])
            ->schema([
                Select::make('block')
                    ->label('Block')
                    ->options(fn (SiteBlocks $blocks): array => $blocks->options())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, Set $set, Get $get, SiteBlocks $blocks): void {
                $sections = (array) ($get('draft.sections') ?? []);
                $sections[] = $blocks->section($data['block']);

                $set('draft.sections', array_values($sections));

                Notification::make()->success()->title('Added to the bottom of the page')->body('Nothing is live until you publish.')->send();
            });
    }

    /**
     * The same page under a new address, as a starting point. Everything     * is copied but the address and the title, and the copy is hidden, so
     * a half-finished duplicate is never live.
     */
    protected static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->schema([
                TextInput::make('slug')
                    ->label('Address for the copy')
                    ->required()
                    ->maxLength(60)
                    ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                    ->default(fn (Page $record): string => app(ManagePages::class)->availableSlug($record->slug.'-copy')),
                TextInput::make('title')
                    ->label('Title for the copy')
                    ->required()
                    ->maxLength(70)
                    ->default(fn (Page $record): string => $record->title.' (copy)'),
            ])
            ->modalDescription('The copy is hidden until you make it visible, and nothing is live until you publish.')
            ->action(function (array $data, Page $record, ManagePages $managePages, $livewire): void {
                try {
                    $copy = $managePages->duplicate($record->slug, $data['slug'], $data['title']);
                } catch (RuntimeException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Copied')->body('The copy is hidden; make it visible when it is ready.')->send();

                $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * A link to the draft of this page for someone without an account,     * good for a few days.
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
        $pageTypes = app(PageTypes::class);
        $types = $pageTypes->creatable();

        $inUse = Page::withTrashed()->distinct()->pluck('type')->filter(fn ($type): bool => is_string($type))->all();

        foreach ([...$inUse, $record?->type] as $type) {
            if (is_string($type) && $type !== '' && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        sort($types);

        return array_combine($types, array_map($pageTypes->label(...), $types));
    }

    /**
     * @return array<string, string>
     */
    protected static function sectionTypeOptions(?string $pageType = null): array
    {
        $types = app(PageTypes::class)->sectionTypesFor($pageType);

        return array_combine($types, array_map(Str::headline(...), $types));
    }

    /**
     * Trashed pages are part of this resource - the Trash filter is how
     * they are found - so the soft-delete scope is lifted here and applied
     * by the filter instead.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
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
