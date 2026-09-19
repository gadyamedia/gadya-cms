<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\PanelBrand;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Content\SiteTheme;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Gadya\Cms\Services\UpdateTheme;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

/**
 * The colours and type of the public site. Everything offered here is
 * curated: the client can make the site hers without being able to make it
 * unreadable.
 *
 * @property-read Schema $form
 */
class ThemeSettings extends Page
{
    protected string $view = 'gadya-cms::filament.pages.theme-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Appearance';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getAppearanceNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Look & feel';

    protected static ?string $title = 'Look & feel';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SiteContentRepository $repository, SiteTheme $theme): void
    {
        $document = $repository->draft();
        $defaults = $theme->defaults();

        $this->form->fill([
            'colors' => array_merge($defaults['colors'], $document['theme']['colors'] ?? []),
            'fonts' => array_merge($defaults['fonts'], $document['theme']['fonts'] ?? []),
            'logo' => $document['logo'] ?? null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $theme = app(SiteTheme::class);

        return $schema
            ->components([
                Form::make([
                    Section::make('Colours')
                        ->description(app(PanelBrand::class)->followsSite()
                            ? 'Used across buttons, headings and backgrounds on the public site - and on this panel and its sign-in screen.'
                            : 'Used across buttons, headings and backgrounds on the public site.')
                        ->schema(array_map(
                            fn (string $key): ColorPicker => ColorPicker::make("colors.{$key}")
                                ->label(Str::headline(str_replace('-', ' ', $key)))
                                ->required()
                                ->rule('regex:'.SiteTheme::COLOR_PATTERN),
                            $theme->allowedColorKeys(),
                        ))
                        ->columns(3),
                    Section::make('Logo')
                        ->description('Shown on the sign-in screen and at the top of this panel.')
                        ->schema([MediaSelect::make('logo', 'Logo')])
                        ->visible(fn (): bool => app(PanelBrand::class)->followsSite()),
                    Section::make('Type')
                        ->schema([
                            Select::make('fonts.display')
                                ->label('Headings')
                                ->options($theme->curatedDisplayFonts())
                                ->required(),
                            Select::make('fonts.sans')
                                ->label('Body text')
                                ->options($theme->curatedSansFonts())
                                ->required(),
                        ])
                        ->columns(2),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Save')->submit('save')->keyBindings(['mod+s']),
                            Action::make('resetToDefaults')
                                ->label('Reset to the original design')
                                ->color('gray')
                                ->requiresConfirmation()
                                ->action('resetToDefaults'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(UpdateTheme $action): void
    {
        $data = $this->form->getState();

        try {
            $action->handle($data['colors'], $data['fonts'], $data['logo'] ?? null);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Saved to your draft')->send();
    }

    public function resetToDefaults(UpdateTheme $action, SiteTheme $theme): void
    {
        $action->reset();

        $this->form->fill($theme->defaults());

        Notification::make()->success()->title('Reset to the original design')->send();
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
