<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use UnitEnum;

/**
 * The details that appear on every page rather than on one: the
 * announcement bar, the phone number, the address, and the locations shown
 * on the contact page.
 *
 * @property-read Schema $form
 */
class SiteDetails extends Page
{
    protected string $view = 'gadya-cms::filament.pages.site-details';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Appearance';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getAppearanceNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Site details';

    protected static ?string $title = 'Site details';

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SiteContentRepository $repository): void
    {
        $document = $repository->draft();

        $this->form->fill([
            'announcement' => (string) ($document['announcement'] ?? ''),
            'phone' => (string) ($document['phone'] ?? ''),
            'address' => (string) ($document['address'] ?? ''),
            'contact_locations' => $document['contact_locations'] ?? [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Everywhere on the site')
                        ->schema([
                            TextInput::make('announcement')->required()->maxLength(120)->columnSpanFull(),
                            TextInput::make('phone')->label('Phone number')->required()->maxLength(40),
                            TextInput::make('address')->required()->maxLength(255),
                        ])
                        ->columns(2),
                    Section::make('Locations')
                        ->description('Shown on the contact page and in the map links.')
                        ->schema([
                            Repeater::make('contact_locations')
                                ->hiddenLabel()
                                ->schema([
                                    TextInput::make('name')->required()->maxLength(80),
                                    TextInput::make('address')->required()->maxLength(255),
                                    TextInput::make('map_query')->label('Map search')->required()->maxLength(255),
                                    Select::make('location')
                                        ->label('Location')
                                        ->options(fn (): array => $this->locationOptions())
                                        ->placeholder('Not tied to a location')
                                        ->helperText('Linking a pin to a location means hiding that place also takes the pin off the map.')
                                        ->nullable(),
                                    TextInput::make('position')->maxLength(80),
                                    TextInput::make('color')->label('Colour name')->maxLength(40),
                                ])
                                ->columns(2)
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
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

        $repository->saveDraft([
            ...$repository->draft(),
            'announcement' => $data['announcement'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'contact_locations' => array_values(array_map(
                fn (array $entry): array => array_filter($entry, fn ($value): bool => $value !== null && $value !== ''),
                $data['contact_locations'] ?? [],
            )),
        ]);

        Notification::make()->success()->title('Saved to your draft')->send();
    }

    /**
     * @return array<string, string>
     */
    private function locationOptions(): array
    {
        $document = app(SiteContentRepository::class)->draft();

        $options = [];

        foreach ($document['locations'] ?? [] as $key => $slug) {
            $options[(string) $key] = $document['pages'][$slug]['title'] ?? $slug;
        }

        return $options;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can((string) config('gadya-cms.gate', 'manage-content')) ?? false;
    }
}
