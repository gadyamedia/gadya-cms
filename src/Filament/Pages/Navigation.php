<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\NavigationTree;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use UnitEnum;

/**
 * The site menu.
 *
 * Each entry is a label and a page to point at. Where a page belongs to a
 * location the link has to go through the location route rather than the
 * plain page address, so that is worked out from the page rather than asked
 * for - it is wiring, and the client should not have to know it exists.
 *
 * @property-read Schema $form
 */
class Navigation extends Page
{
    protected string $view = 'gadya-cms::filament.pages.navigation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static string|UnitEnum|null $navigationGroup = 'Appearance';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getAppearanceNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Menu';

    protected static ?string $title = 'Menu';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SiteContentRepository $repository, NavigationTree $tree): void
    {
        $document = $repository->draft();
        $state = [];

        foreach (array_keys(NavigationTree::menus()) as $key) {
            data_set($state, static::statePath($key), $tree->forMenu($document, $key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make($this->menuSections())
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * One section per menu the site has. With the single menu most sites
     * have, this is the screen it always was.
     *
     * @return list<Section>
     */
    private function menuSections(): array
    {
        $sections = [];
        $first = true;

        foreach (NavigationTree::menus() as $key => $label) {
            $sections[] = Section::make($label)
                ->description($first
                    ? 'Drag to reorder. A menu item can hold sub items, which appear as a drop-down on the site. Anything you hide in Pages drops out of the menu on its own.'
                    : 'The same idea, for wherever your templates put this menu.')
                ->collapsible(! $first)
                ->collapsed(! $first)
                ->schema([$this->menuRepeater($key)]);

            $first = false;
        }

        return $sections;
    }

    /**
     * Where a menu sits in the form. The main one keeps the name it has
     * always had, so an application that fills this form in a test of its
     * own is not broken by the arrival of a second menu.
     */
    public static function statePath(string $key): string
    {
        return $key === NavigationTree::PRIMARY ? 'nav' : "menus.{$key}";
    }

    private function menuRepeater(string $key): Repeater
    {
        return Repeater::make(static::statePath($key))
            ->hiddenLabel()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(60),
                $this->pageSelect()
                    ->helperText('Leave empty for a heading that only opens its sub items.')
                    ->nullable(),
                Select::make('side')
                    ->label('Side of the header')
                    ->options([
                        NavigationTree::SIDE_START => 'Left of the logo',
                        NavigationTree::SIDE_END => 'Right of the logo',
                    ])
                    /*
                                         * Not required: an item that arrives without
                                         * a side is placed on the left when it is
                                         * saved, rather than refusing the save over
                                         * something the client never chose.
                                         */
                    ->default(NavigationTree::SIDE_START),
                Toggle::make('highlight')
                    ->label('Show as a button')
                    ->helperText('Draws the eye, for the one thing you most want clicked.'),
                Repeater::make('children')
                    ->label('Sub items')
                    ->schema([
                        TextInput::make('label')
                            ->required()
                            ->maxLength(60),
                        $this->pageSelect()->required(),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->collapsed()
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->addActionLabel('Add a sub item')
                    ->columnSpanFull(),
            ])
            ->columns(2)
            ->reorderable()
            ->collapsed()
            ->cloneable()
            ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
            ->addActionLabel('Add a menu item')
            ->columnSpanFull();
    }

    public function save(SiteContentRepository $repository): void
    {
        $data = $this->form->getState();
        $document = $repository->draft();

        foreach (array_keys(NavigationTree::menus()) as $key) {
            $items = $this->clean((array) data_get($data, static::statePath($key), []), $document);

            if ($key === NavigationTree::PRIMARY) {
                $document['nav'] = $items;
            } else {
                $document['menus'][$key] = $items;
            }
        }

        $repository->saveDraft($document);

        Notification::make()->success()->title('Saved to your draft')->send();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    private function clean(array $items, array $document): array
    {
        return array_values(array_map(function (array $item) use ($document): array {
            $children = $this->withLocationRouting($item['children'] ?? [], $document);

            $side = ($item['side'] ?? null) === NavigationTree::SIDE_END
                ? NavigationTree::SIDE_END
                : NavigationTree::SIDE_START;
            $highlight = (bool) ($item['highlight'] ?? false);

            $item = $this->withLocationRouting([$item], $document)[0];
            unset($item['children']);

            $item['side'] = $side;

            if ($highlight) {
                $item['highlight'] = true;
            }

            return $children === [] ? $item : [...$item, 'children' => $children];
        }, $items));
    }

    private function pageSelect(): Select
    {
        return Select::make('slug')
            ->label('Goes to')
            ->options(fn (): array => $this->pageOptions())
            ->searchable()
            /*
             * A searchable select loads its options on demand, so without
             * this it has no label for the page it was given and renders
             * empty.
             */
            ->getOptionLabelUsing(fn (?string $value): ?string => $value === null || $value === ''
                ? null
                : ($this->pageOptions()[$value] ?? $value));
    }

    /**
     * Restore the `location` key on any entry pointing at a location, and
     * drop it from the rest, so the menu always links through the right
     * route without the client choosing between two kinds of address.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $document
     * @return array<int, array<string, mixed>>
     */
    private function withLocationRouting(array $items, array $document): array
    {
        $locationOf = array_flip(array_filter($document['locations'] ?? [], 'is_string'));

        return array_values(array_map(function (array $item) use ($locationOf): array {
            $item = array_filter([
                'label' => $item['label'] ?? null,
                'slug' => $item['slug'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');

            if (isset($locationOf[$item['slug'] ?? null])) {
                $item['location'] = $locationOf[$item['slug']];
            }

            return $item;
        }, $items));
    }

    /**
     * @return array<string, string>
     */
    private function pageOptions(): array
    {
        $pages = app(SiteContentRepository::class)->draft()['pages'] ?? [];

        $options = [];

        foreach ($pages as $slug => $page) {
            $options[(string) $slug] = ($page['title'] ?? $slug).' ('.$slug.')';
        }

        asort($options);

        return $options;
    }

    /**
     * Saving writes the draft; this is what makes it live. On the screen
     * itself, so nobody has to know to go and find it elsewhere.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [PublishChangesAction::make()];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
    }
}
