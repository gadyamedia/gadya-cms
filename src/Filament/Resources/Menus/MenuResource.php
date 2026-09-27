<?php

namespace Gadya\Cms\Filament\Resources\Menus;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Menus\Pages\CreateMenu;
use Gadya\Cms\Filament\Resources\Menus\Pages\EditMenu;
use Gadya\Cms\Filament\Resources\Menus\Pages\ListMenus;
use Gadya\Cms\Filament\Resources\Menus\RelationManagers\SectionsRelationManager;
use Gadya\Cms\Models\Menu;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * A restaurant's menus - breakfast, lunch, drinks - and their sections.
 * The items themselves are on their own screen, where the sold-out
 * switches are one tap each.
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Food menus';

    protected static ?string $modelLabel = 'menu';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Breakfast')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, ?string $state, ?Menu $record): void {
                            if ($record === null) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('Short name')
                        ->helperText('What the site uses to find this menu: <x-gadya-cms::menu menu="…" />. Changing it means changing the page too.')
                        ->required()
                        ->maxLength(80)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('site_id', app(SiteContext::class)->id())),
                    Textarea::make('description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                    TextInput::make('availability')
                        ->label('When it is served')
                        ->placeholder('Weekdays until 11am')
                        ->maxLength(255),
                    Select::make('status')
                        ->options([
                            Menu::STATUS_DRAFT => 'Draft - only you can see it',
                            Menu::STATUS_PUBLISHED => 'On the site',
                        ])
                        ->default(Menu::STATUS_DRAFT)
                        ->required()
                        ->native(false),
                ])
                ->columns(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('availability')->label('Served')->placeholder('—')->toggleable(),
                TextColumn::make('sections_count')->counts('sections')->label('Sections'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Menu::STATUS_PUBLISHED ? 'On the site' : 'Draft')
                    ->color(fn (string $state): string => $state === Menu::STATUS_PUBLISHED ? 'success' : 'warning'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('The menu, its sections and every item on it are deleted.'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    public static function getRelations(): array
    {
        return [SectionsRelationManager::class];
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
            'index' => ListMenus::route('/'),
            'create' => CreateMenu::route('/create'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
