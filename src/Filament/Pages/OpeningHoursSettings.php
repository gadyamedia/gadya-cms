<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Hours\BusinessHours;
use Gadya\Cms\Hours\OpeningHours;
use UnitEnum;

/**
 * The one place the business's opening hours are kept: every week, and
 * the holidays and special days that differ. The hours table, the "Open
 * now" badge, Google's structured data and the portal all read from here,
 * so changing them once changes them everywhere - at the next Publish.
 *
 * @property-read Schema $form
 */
class OpeningHoursSettings extends Page
{
    protected string $view = 'gadya-cms::filament.pages.opening-hours';

    protected static ?string $slug = 'opening-hours';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Appearance';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getAppearanceNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Opening hours';

    protected static ?string $title = 'Opening hours';

    protected static ?int $navigationSort = 4;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(BusinessHours $hours): void
    {
        $this->form->fill(static::toFormState($hours->draft()));
    }

    public function form(Schema $schema): Schema
    {
        $days = [];

        foreach (OpeningHours::DAYS as $day => $name) {
            $days[] = static::rangesRepeater("regular.{$day}", $name)
                ->helperText('No times means closed.');
        }

        return $schema
            ->components([
                Form::make([
                    Section::make('Every week')
                        ->description('Add a second set of times for a day with a break, such as lunch and dinner. A closing time earlier than the opening time runs past midnight.')
                        ->schema([
                            Grid::make(['default' => 1, 'lg' => 2])->schema($days),
                        ]),
                    Section::make('Holidays and special days')
                        ->description('A date here replaces that day’s usual hours: closed for Thanksgiving, open late on New Year’s Eve.')
                        ->schema([
                            Repeater::make('exceptions')
                                ->hiddenLabel()
                                ->schema([
                                    DatePicker::make('date')->required()->native(false)->displayFormat('D, M j, Y'),
                                    TextInput::make('label')->label('What for')->placeholder('Thanksgiving')->maxLength(80),
                                    Toggle::make('closed')->label('Closed all day')->default(true)->live()->columnSpanFull(),
                                    static::rangesRepeater('ranges', 'Open')
                                        ->visible(fn (Get $get): bool => ! $get('closed'))
                                        ->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->defaultItems(0)
                                ->addActionLabel('Add a holiday or special day')
                                ->itemLabel(fn (array $state): ?string => trim(($state['date'] ?? '').' '.($state['label'] ?? '')) ?: null)
                                ->collapsible(),
                        ]),
                    Section::make('Time zone')
                        ->schema([
                            Select::make('timezone')
                                ->label('Where the business is')
                                ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                                ->searchable()
                                ->required(),
                        ])
                        ->collapsible()
                        ->collapsed(),
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
        $hours = static::fromFormState($this->form->getState());

        $repository->saveDraft([
            ...$repository->draft(),
            BusinessHours::KEY => $hours->toArray(),
        ]);

        Notification::make()->success()->title('Saved to your draft')->body('Publish to show the new hours on the site.')->send();
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFormState(OpeningHours $hours): array
    {
        $pairs = fn (array $ranges): array => array_map(fn (array $range): array => ['open' => $range[0], 'close' => $range[1] === '24:00' ? '00:00' : $range[1]], $ranges);

        return [
            'timezone' => $hours->timezone(),
            'regular' => array_map($pairs, $hours->regular()),
            'exceptions' => array_map(fn (array $exception): array => [
                'date' => $exception['date'],
                'label' => $exception['label'],
                'closed' => $exception['closed'],
                'ranges' => $pairs($exception['ranges']),
            ], $hours->exceptions()),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromFormState(array $state): OpeningHours
    {
        $pairs = fn (mixed $ranges): array => array_values(array_map(
            fn (array $range): array => [(string) ($range['open'] ?? ''), (string) ($range['close'] ?? '')],
            array_filter((array) $ranges, 'is_array'),
        ));

        $regular = [];

        foreach (array_keys(OpeningHours::DAYS) as $day) {
            $regular[$day] = $pairs($state['regular'][$day] ?? []);
        }

        return OpeningHours::fromArray([
            'timezone' => $state['timezone'] ?? null,
            'regular' => $regular,
            'exceptions' => array_values(array_map(fn (array $exception): array => [
                'date' => (string) ($exception['date'] ?? ''),
                'label' => $exception['label'] ?? null,
                'closed' => (bool) ($exception['closed'] ?? false),
                'ranges' => $pairs($exception['ranges'] ?? []),
            ], array_filter((array) ($state['exceptions'] ?? []), 'is_array'))),
        ]);
    }

    private static function rangesRepeater(string $name, string $label): Repeater
    {
        return Repeater::make($name)
            ->label($label)
            ->schema([
                TimePicker::make('open')->label('Opens')->seconds(false)->format('H:i')->required(),
                TimePicker::make('close')->label('Closes')->seconds(false)->format('H:i')->required(),
            ])
            ->columns(2)
            ->defaultItems(0)
            ->addActionLabel('Add times')
            ->reorderable(false);
    }

    /**
     * Saving writes the draft; this is what makes it live.
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
