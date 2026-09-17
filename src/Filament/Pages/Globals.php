<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Schemas\MediaSelect;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The words and pictures that appear on every page rather than on one:
 * the announcement bar, the footer, a call-to-action strip. Each is a
 * top-level path in the site document, listed in `gadya-cms.globals`, and
 * editable on the page too through `@editableGlobal`.
 *
 * @property-read Schema $form
 */
class Globals extends Page
{
    protected string $view = 'gadya-cms::filament.pages.globals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Appearance';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getAppearanceNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Everywhere';

    protected static ?string $title = 'Everywhere on the site';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(SiteContentRepository $repository): void
    {
        $document = $repository->draft();
        $state = [];

        foreach (array_keys(static::fields()) as $path) {
            Arr::set($state, $path, Arr::get($document, $path));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (static::grouped() as $group => $fields) {
            $sections[] = Section::make($group)->schema($fields)->columns(2);
        }

        return $schema
            ->components([
                Form::make($sections)
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

        foreach (array_keys(static::fields()) as $path) {
            Arr::set($document, $path, Arr::get($data, $path));
        }

        $repository->saveDraft($document);

        Notification::make()->success()->title('Saved to your draft')->send();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        $fields = [];

        foreach ((array) config('gadya-cms.globals', []) as $path => $field) {
            if (is_string($path) && is_array($field)) {
                $fields[$path] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, list<Component>>
     */
    protected static function grouped(): array
    {
        $grouped = [];

        foreach (static::fields() as $path => $field) {
            $group = (string) ($field['group'] ?? 'Everywhere on the site');
            $label = (string) ($field['label'] ?? Str::headline(Str::afterLast($path, '.')));

            $grouped[$group][] = match ($field['type'] ?? 'text') {
                'textarea' => Textarea::make($path)->label($label)->rows((int) ($field['rows'] ?? 3))->maxLength((int) ($field['max'] ?? 2000))->required((bool) ($field['required'] ?? false))->columnSpanFull(),
                'image' => MediaSelect::make($path, $label),
                default => TextInput::make($path)->label($label)->maxLength((int) ($field['max'] ?? 255))->required((bool) ($field['required'] ?? false)),
            };
        }

        return $grouped;
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

    public static function shouldRegisterNavigation(): bool
    {
        return static::fields() !== [];
    }
}
