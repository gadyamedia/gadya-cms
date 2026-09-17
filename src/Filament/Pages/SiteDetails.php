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
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use UnitEnum;

/**
 * The places the business operates from, as shown on the contact page and
 * in the map links. The words that appear on every page live under
 * Everywhere.
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

    protected static ?string $navigationLabel = 'Locations';

    protected static ?string $title = 'Locations';

    protected static ?int $navigationSort = 3;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SiteContentRepository $repository): void
    {
        $document = $repository->draft();

        $this->form->fill([
            'contact_locations' => $document['contact_locations'] ?? [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
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
