<?php

namespace Gadya\Cms\Filament\Resources\MenuItems;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\EditMenuItem;
use Gadya\Cms\Filament\Resources\MenuItems\Pages\ListMenuItems;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Gadya\Cms\Menus\Dietary;
use Gadya\Cms\Menus\Price;
use Gadya\Cms\Models\Menu;
use Gadya\Cms\Models\MenuItem;
use Gadya\Cms\Models\MenuSection;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Everything on every menu, with the switches the counter needs through
 * the day - sold out, back in, today's special - one tap each and live
 * on the site at once. There is nothing to publish: a sold-out bagel
 * that stays on the site until someone remembers to press Publish is
 * exactly the problem this solves.
 */
class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Menu items & sold out';

    protected static ?string $modelLabel = 'menu item';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 8;

    public static function getNavigationBadge(): ?string
    {
        $soldOut = rescue(fn (): int => static::getEloquentQuery()->where('is_sold_out', true)->count(), 0, report: false);

        return $soldOut > 0 ? $soldOut.' sold out' : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The item')
                ->schema([
                    Select::make('menu_section_id')
                        ->label('Section')
                        ->options(fn (): array => static::sectionOptions())
                        ->required()
                        ->native(false),
                    TextInput::make('name')->required()->maxLength(160),
                    Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                    CheckboxList::make('dietary')
                        ->label('Dietary and allergens')
                        ->options(fn (): array => Dietary::options())
                        ->columns(3)
                        ->columnSpanFull(),
                    TextInput::make('availability')
                        ->label('When it is available')
                        ->placeholder('Weekdays until 11am')
                        ->maxLength(255),
                ])
                ->columns(2),
            Section::make('Price')
                ->description('One price, or a price for each size. Leave both empty for "ask us".')
                ->schema([
                    static::priceInput('price_cents')->label('Price'),
                    Repeater::make('variants')
                        ->label('Sizes')
                        ->schema([
                            /* A size keeps its key through renames, for an ordering system to hold on to. */
                            Hidden::make('key'),
                            TextInput::make('label')->label('Size')->required()->maxLength(60)->placeholder('Large'),
                            static::priceInput('price_cents')->label('Price')->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add a size')
                        ->reorderable()
                        ->columnSpanFull(),
                ])
                ->collapsible(),
            Section::make('Photo and flags')
                ->schema([
                    MediaSelect::make('image', 'Photo'),
                    TextInput::make('image_alt')->label('Describe the photo')->maxLength(160),
                    Toggle::make('is_featured')->label('Special')->helperText('Picked out on the menu and in the specials list.'),
                    Toggle::make('is_sold_out')->label('Sold out')->helperText('Stays on the menu, marked sold out.'),
                    Toggle::make('is_visible')->label('On the menu')->default(true)->helperText('Off hides it without deleting it - for something seasonal.'),
                ])
                ->columns(2)
                ->collapsible(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->wrap()
                    ->description(fn (MenuItem $record): string => $record->section?->menu?->name.' › '.$record->section?->name),
                TextColumn::make('price_cents')
                    ->label('Price')
                    ->state(fn (MenuItem $record): string => collect($record->prices())
                        ->map(fn (array $price): string => ($price['label'] ? $price['label'].' ' : '').Price::format($price['price_cents']))
                        ->implode(' · ') ?: '—'),
                ToggleColumn::make('is_sold_out')->label('Sold out'),
                ToggleColumn::make('is_featured')->label('Special'),
                ToggleColumn::make('is_visible')->label('On the menu')->toggleable(),
            ])
            ->groups([
                Group::make('menu_section_id')
                    ->label('Section')
                    ->getTitleFromRecordUsing(fn (MenuItem $record): string => $record->section?->menu?->name.' › '.$record->section?->name),
            ])
            ->defaultGroup('menu_section_id')
            ->filters([
                SelectFilter::make('menu')
                    ->label('Menu')
                    ->options(fn (): array => Menu::query()->where('site_id', app(SiteContext::class)->id())->orderBy('sort_order')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('section', fn (Builder $section) => $section->where('menu_id', $data['value']))
                        : $query),
                SelectFilter::make('section')
                    ->label('Section')
                    ->options(fn (): array => static::sectionOptions())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('menu_section_id', $data['value'])
                        : $query),
                TernaryFilter::make('is_sold_out')->label('Sold out'),
            ])
            ->headerActions([
                Action::make('allBackIn')
                    ->label('Everything back in')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Every item marked sold out is shown as available again - for the start of the day.')
                    ->action(fn () => static::getEloquentQuery()->where('is_sold_out', true)->get()->each->update(['is_sold_out' => false])),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('soldOut')
                        ->label('Mark sold out')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->action(fn (Collection $records) => $records->each->update(['is_sold_out' => true])),
                    BulkAction::make('backIn')
                        ->label('Mark available')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(fn (Collection $records) => $records->each->update(['is_sold_out' => false])),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order');
    }

    /**
     * A price typed as dollars and cents, kept as whole cents.
     */
    protected static function priceInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->prefix((string) config('gadya-cms.menus.currency_symbol', '$'))
            ->inputMode('decimal')
            ->placeholder('4.50')
            ->rule('nullable')
            ->rule('regex:/^\$?\d{1,6}(\.\d{1,2})?$/')
            ->formatStateUsing(fn ($state): ?string => is_numeric($state) ? Price::toDecimal((int) $state) : $state)
            ->dehydrateStateUsing(fn ($state): ?int => Price::toCents($state));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function sectionOptions(): array
    {
        $options = [];

        $sections = MenuSection::query()
            ->with('menu')
            ->whereHas('menu', fn (Builder $menu) => $menu->where('site_id', app(SiteContext::class)->id()))
            ->get()
            ->sortBy(fn (MenuSection $section): array => [$section->menu?->sort_order, $section->sort_order]);

        foreach ($sections as $section) {
            $options[(string) $section->menu?->name][$section->getKey()] = $section->name;
        }

        return $options;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forSite(app(SiteContext::class)->id())->with('section.menu');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }
}
