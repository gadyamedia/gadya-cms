<?php

namespace Gadya\Cms\Filament\Resources\Posts;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Blog\ArticleRequest;
use Gadya\Cms\Blog\BlogRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Posts\Pages\CreatePost;
use Gadya\Cms\Filament\Resources\Posts\Pages\EditPost;
use Gadya\Cms\Filament\Resources\Posts\Pages\ListPosts;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Gadya\Cms\Filament\Schemas\SeoSection;
use Gadya\Cms\Models\Post;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Articles. A writing screen rather than a settings screen: the draft
 * gets the room, and everything that decorates it sits in a rail beside.
 */
class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Articles';

    protected static ?string $modelLabel = 'article';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        $prefix = rtrim((string) config('app.url'), '/').'/'.trim((string) config('gadya-cms.blog.prefix', 'blog'), '/').'/';

        return $schema->components([
            Grid::make(3)->schema([
                Group::make()->schema([
                    Section::make()->schema([
                        TextInput::make('title')
                            ->hiddenLabel()
                            ->placeholder('Add a title')
                            ->required()
                            ->maxLength(255)
                            ->extraInputAttributes(['class' => 'gadya-post__title'])
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, ?Post $record): void {
                                if ($record === null) {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->label('Address')
                            ->prefix($prefix)
                            ->required()
                            ->maxLength(120)
                            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                            ->unique(ignoreRecord: true),
                    ])->compact(),

                    Section::make()->schema([
                        RichEditor::make('content')
                            ->hiddenLabel()
                            ->placeholder('Start writing, or ask for a draft under Write with AI.')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['h2', 'h3'],
                                ['bulletList', 'orderedList', 'blockquote'],
                                ['table', 'horizontalRule', 'attachFiles'],
                                ['clearFormatting', 'undo', 'redo'],
                            ])
                            ->fileAttachmentsDisk((string) config('gadya-cms.media.disk', 'public'))
                            ->fileAttachmentsVisibility('public')
                            ->fileAttachmentsDirectory((string) config('gadya-cms.media.directory', 'site-media').'/articles')
                            ->extraInputAttributes(['class' => 'gadya-post__body'])
                            ->columnSpanFull(),
                    ]),

                    Section::make('Questions and answers')
                        ->description('Shown under the article, and published as FAQ structured data for search engines.')
                        ->icon(Heroicon::OutlinedQuestionMarkCircle)
                        ->schema([
                            Repeater::make('faq')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('question')->required()->maxLength(255),
                                    Textarea::make('answer')->rows(2)->required(),
                                ])
                                ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                                ->collapsible()
                                ->collapsed()
                                ->defaultItems(0)
                                ->addActionLabel('Add a question'),
                        ])->collapsible()->collapsed(),

                    Section::make('How findable is it?')
                        ->icon(Heroicon::OutlinedChartBar)
                        ->schema([
                            ViewField::make('audit')
                                ->hiddenLabel()
                                ->dehydrated(false)
                                ->view('gadya-cms::filament.forms.audit-panel'),
                        ])->collapsible(),
                ])->columnSpan(2),

                Group::make()->schema([
                    Section::make('Publish')
                        ->icon(Heroicon::OutlinedPaperAirplane)
                        ->schema([
                            Select::make('status')
                                ->options([
                                    Post::STATUS_DRAFT => 'Draft',
                                    Post::STATUS_PUBLISHED => 'Published',
                                ])
                                ->default(Post::STATUS_DRAFT)
                                ->required()
                                ->native(false)
                                ->helperText('A draft is only visible here.'),
                            DateTimePicker::make('published_at')
                                ->label('Publish date')
                                ->seconds(false)
                                ->default(now())
                                ->helperText('A date in the future schedules it.'),
                            TextInput::make('reading_time')->placeholder('6 min read')->maxLength(20),
                        ]),

                    Section::make('Photo')
                        ->icon(Heroicon::OutlinedPhoto)
                        ->schema([
                            MediaSelect::make('image', 'Photo'),
                            TextInput::make('hero_alt')
                                ->label('Describe the photo')
                                ->maxLength(140)
                                ->helperText('For screen readers and image search.'),
                        ])->collapsible(),

                    Section::make('Excerpt')
                        ->icon(Heroicon::OutlinedBars3BottomLeft)
                        ->schema([
                            Textarea::make('excerpt')
                                ->hiddenLabel()
                                ->rows(4)
                                ->maxLength(500)
                                ->helperText('Shown in the article list and in link previews.'),
                        ])->collapsible(),

                    Section::make('In search results')
                        ->icon(Heroicon::OutlinedMagnifyingGlass)
                        ->headerActions(array_values(array_filter([SeoSection::writeAction('')])))
                        ->schema([
                            ViewField::make('serp')
                                ->hiddenLabel()
                                ->dehydrated(false)
                                ->view('gadya-cms::filament.forms.serp-preview')
                                ->viewData(['path' => '', 'urlPrefix' => (string) config('gadya-cms.blog.prefix', 'blog')]),
                            TextInput::make('meta_title')
                                ->label('Title in search results')
                                ->maxLength(70)
                                ->live(onBlur: true)
                                ->helperText(fn (?string $state): string => strlen((string) $state).' of 30–60 characters'),
                            Textarea::make('meta_description')
                                ->label('Description in search results')
                                ->rows(3)
                                ->maxLength(320)
                                ->live(onBlur: true)
                                ->helperText(fn (?string $state): string => strlen((string) $state).' of 140–160 characters'),
                        ])->collapsible(),

                    Section::make('Filed under')
                        ->icon(Heroicon::OutlinedTag)
                        ->description('Categories are shelves, tags are labels. Each gets a page of its own.')
                        ->schema([
                            static::termSelect('category_ids', 'Categories', Term::CATEGORY),
                            static::termSelect('tag_ids', 'Tags', Term::TAG),
                        ])->collapsible(),

                    Section::make('Aim')->icon(Heroicon::OutlinedFlag)
                        ->schema([
                            TextInput::make('target_keyword')->label('Search phrase')->maxLength(120),
                            TextInput::make('target_location')->label('Place')->maxLength(120),
                            Select::make('search_intent')
                                ->label('What the reader wants')
                                ->options(array_combine(ArticleRequest::intents(), array_map(Str::headline(...), ArticleRequest::intents())))
                                ->native(false)
                                ->placeholder('Not set'),
                        ])->collapsible()->collapsed(),
                ])->columnSpan(1),
            ]),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('status')
                    ->state(fn (Post $record): string => static::stateLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Scheduled' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('categories.name')
                    ->label('Filed under')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('source')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Post::SOURCE_AI ? 'AI draft' : 'Written')
                    ->color(fn (string $state): string => $state === Post::SOURCE_AI ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('published_at')->label('Published')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->label('Edited')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Post::STATUS_DRAFT => 'Draft',
                    Post::STATUS_PUBLISHED => 'Published',
                ]),
                SelectFilter::make('terms')
                    ->label('Filed under')
                    ->relationship('terms', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                static::duplicateAction(),
                DeleteAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * A copy to work from, as a draft, with its own address - the fastest
     * way to write the next article in a series.
     */
    protected static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalDescription('A copy is made as a draft, with (copy) on the end of its title. Nothing is published.')
            ->action(function (Post $record, BlogRepository $blog, $livewire): void {
                $copy = $record->replicate(['published_at']);
                $copy->title = $record->title.' (copy)';
                $copy->slug = $blog->uniqueSlug($record->slug.'-copy');
                $copy->status = Post::STATUS_DRAFT;
                $copy->published_at = null;
                $copy->author_id = auth()->id();
                $copy->save();

                $copy->terms()->sync($record->terms()->pluck('gadyacms_terms.id')->all());

                Notification::make()->success()->title('Copied')->body('Open the copy and change what you need.')->send();

                $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * The terms of one kind, with a way to add one without leaving the
     * article - the moment a writer needs a new category is while writing.
     */
    protected static function termSelect(string $name, string $label, string $taxonomy): Select
    {
        return Select::make($name)
            ->label($label)
            ->multiple()
            ->preload()
            ->options(fn (): array => Term::query()
                ->where('site_id', app(SiteContext::class)->id())
                ->where('taxonomy', $taxonomy)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all())
            ->createOptionForm([
                TextInput::make('name')->required()->maxLength(80),
                Textarea::make('description')->rows(2)->maxLength(500),
            ])
            ->createOptionUsing(fn (array $data): int => (int) Term::query()->create([
                'taxonomy' => $taxonomy,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ])->getKey())
            ->dehydrated(false);
    }

    /**
     * The terms as the form holds them, ready to sync.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    public static function termIdsFrom(array $data): array
    {
        return array_values(array_unique(array_map('intval', [
            ...(array) ($data['category_ids'] ?? []),
            ...(array) ($data['tag_ids'] ?? []),
        ])));
    }

    public static function stateLabel(Post $post): string
    {
        return match (true) {
            $post->isLive() => 'Live',
            $post->isScheduled() => 'Scheduled',
            default => 'Draft',
        };
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::ARTICLES)) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPosts::route('/'),
            'create' => CreatePost::route('/create'),
            'edit' => EditPost::route('/{record}/edit'),
        ];
    }
}
