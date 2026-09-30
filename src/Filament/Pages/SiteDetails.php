<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Support\SiteTimezone;
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

    /** @var array<string, mixed>|null */
    public ?array $timezoneData = [];

    public function mount(SiteContentRepository $repository, SiteTimezone $timezone): void
    {
        $document = $repository->draft();

        $this->timezoneForm->fill(['timezone' => $timezone->explicitName()]);

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

    /**
     * The business's own time zone. Not part of the draft: it is how every
     * date and time is shown, so it takes effect as soon as it is saved.
     */
    public function timezoneForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Time zone')
                        ->description('Where the business is, for the clock.')
                        ->schema([
                            Select::make('timezone')
                                ->label('Time zone')
                                ->options(fn (): array => SiteTimezone::groupedOptions())
                                ->searchable()
                                ->placeholder(fn (): string => 'Not chosen - using '.app(SiteTimezone::class)->defaultName())
                                ->helperText('Every date and time in your admin, your emails and your reports is shown in this time zone.')
                                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (filled($value) && ! SiteTimezone::isValid((string) $value)) {
                                        $fail('That is not a time zone this site knows.');
                                    }
                                })
                                ->nullable(),
                            View::make('gadya-cms::filament.pages.use-device-timezone'),
                        ]),
                ])
                    ->livewireSubmitHandler('saveTimezone')
                    ->footer([
                        Actions::make([
                            Action::make('saveTimezone')->label('Save time zone')->submit('saveTimezone'),
                        ]),
                    ]),
            ])
            ->statePath('timezoneData');
    }

    public function saveTimezone(SiteTimezone $timezone): void
    {
        $chosen = $this->timezoneForm->getState()['timezone'] ?? null;

        $timezone->set(filled($chosen) ? (string) $chosen : null);

        Notification::make()->success()->title('Time zone saved')->body('Dates and times now show in '.$timezone->name().'.')->send();
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
