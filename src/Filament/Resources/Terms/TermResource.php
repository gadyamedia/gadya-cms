<?php

namespace Gadya\Cms\Filament\Resources\Terms;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Terms\Pages\ListTerms;
use Gadya\Cms\Models\Term;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The shelves and the labels: categories an article is filed under, tags
 * it shares with others. Each has a page of its own on the site.
 */
class TermResource extends Resource
{
    protected static ?string $model = Term::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Categories & tags';

    protected static ?string $modelLabel = 'category or tag';

    protected static ?string $pluralModelLabel = 'categories and tags';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('taxonomy')
                ->label('Kind')
                ->options(Term::taxonomyLabels())
                ->default(Term::CATEGORY)
                ->required()
                ->native(false)
                ->helperText('A category is a shelf an article sits on; a tag is a word it shares with others.'),
            TextInput::make('name')->required()->maxLength(80),
            TextInput::make('slug')
                ->label('Address')
                ->maxLength(80)
                ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->helperText('Left blank, it is made from the name.'),
            Textarea::make('description')
                ->rows(2)
                ->maxLength(500)
                ->helperText('Shown at the top of its page, and in search results.'),
            TextInput::make('sort_order')->label('Order')->numeric()->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('taxonomy')
                    ->label('Kind')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Term::taxonomyLabels()[$state] ?? Str::headline($state))
                    ->color(fn (string $state): string => $state === Term::CATEGORY ? 'primary' : 'gray'),
                TextColumn::make('slug')
                    ->label('Address')
                    ->state(fn (Term $record): string => $record->publicPath())
                    ->url(fn (Term $record): string => url($record->publicPath()))
                    ->openUrlInNewTab()
                    ->toggleable(),
                TextColumn::make('posts_count')->label('Articles')->counts('posts')->sortable(),
                TextColumn::make('description')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('taxonomy')->label('Kind')->options(Term::taxonomyLabels()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()])
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::ARTICLES)) ?? false;
    }

    public static function getPages(): array
    {
        return ['index' => ListTerms::route('/')];
    }
}
