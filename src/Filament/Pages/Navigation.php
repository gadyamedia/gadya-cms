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
use Gadya\Cms\Content\NavigationTree;
use Gadya\Cms\Content\SiteContentRepository;
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

        $this->form->fill([
            'nav' => $tree->fromDocument($document['nav'] ?? [], $tree->locationsParentSlug($document)),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Menu items')
                        ->description('Drag to reorder. A menu item can hold sub items, which appear as a drop-down on the site. Anything you hide in Pages drops out of the menu on its own.')
                        ->schema([
                            Repeater::make('nav')
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
                                ->columnSpanFull(),
                        ]),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(SiteContentRepository $repository): void
    {
        $data = $this->form->getState();
        $document = $repository->draft();

        $repository->saveDraft([
            ...$document,
            'nav' => $this->clean($data['nav'] ?? [], $document),
        ]);

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

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }
}
