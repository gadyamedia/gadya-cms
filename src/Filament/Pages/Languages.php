<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\TranslateContent;
use Gadya\Cms\Localisation\Translator;
use Illuminate\Http\RedirectResponse;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The site's other languages: how far each page, menu and article has
 * got, what the machine translated and a person has not read yet, and the
 * words that must never be translated.
 *
 * Only there when the site has a second language switched on.
 *
 * @property-read Schema $form
 */
class Languages extends Page
{
    protected string $view = 'gadya-cms::filament.pages.languages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Languages';

    protected static ?string $title = 'Languages';

    protected static ?int $navigationSort = 3;

    #[Url]
    public string $locale = '';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(Translator $translator): void
    {
        if (! in_array($this->locale, app(Locales::class)->additional(), true)) {
            $this->locale = app(Locales::class)->additional()[0] ?? '';
        }

        $this->form->fill(['glossary' => $translator->glossaryOption()]);
    }

    public static function canAccess(): bool
    {
        return app(Locales::class)->isMultilingual()
            && (auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Never translated')
                        ->description('Names and words the translator leaves exactly as written: product names, a slogan, a town. The business\'s own name is always kept.')
                        ->schema([
                            TagsInput::make('glossary')
                                ->hiddenLabel()
                                ->placeholder('Add a word or name')
                                ->splitKeys(['Enter', ',']),
                        ]),
                ])
                    ->livewireSubmitHandler('saveGlossary')
                    ->footer([
                        Actions::make([
                            Action::make('saveGlossary')->label('Save')->submit('saveGlossary'),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function saveGlossary(Translator $translator): void
    {
        $translator->saveGlossary(array_map('strval', (array) ($this->form->getState()['glossary'] ?? [])));

        Notification::make()->success()->title('Saved')->send();
    }

    /**
     * Every piece of the site, with how far along it is in this language.
     *
     * @return list<array{key: string, label: string, kind: string, path: string|null, status: string}>
     */
    public function getRowsProperty(): array
    {
        $content = app(TranslateContent::class);
        $rows = [];

        foreach ($content->units() as $key => $unit) {
            if ($content->leaves($key) === []) {
                continue;
            }

            $rows[] = [...$unit, 'key' => $key, 'status' => $content->status($key, $this->locale)];
        }

        return $rows;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            TranslateContent::STATUS_MISSING => 'Not translated',
            TranslateContent::STATUS_REVIEW => 'Machine translated - to review',
            TranslateContent::STATUS_OUTDATED => 'Original changed since',
            default => 'Translated',
        };
    }

    public function languageName(): string
    {
        return app(Locales::class)->name($this->locale);
    }

    public function canTranslate(): bool
    {
        return app(Translator::class)->available();
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('translateSite')
                ->label('Translate the whole site')
                ->icon(Heroicon::OutlinedLanguage)
                ->visible(fn (): bool => $this->canTranslate())
                ->modalDescription(fn (): string => 'Everything not yet translated into '.$this->languageName().', or changed since, is translated in the background. It arrives as drafts marked for review: nothing goes live until someone has read it and it is published.')
                ->schema(count(app(Locales::class)->additional()) > 1 ? [
                    Select::make('locale')
                        ->label('Into')
                        ->options(fn (): array => collect(app(Locales::class)->additional())->mapWithKeys(fn (string $code): array => [$code => app(Locales::class)->name($code)])->all())
                        ->default(fn (): string => $this->locale)
                        ->required(),
                ] : [])
                ->action(function (array $data, TranslateContent $content): void {
                    $locale = (string) ($data['locale'] ?? $this->locale);
                    $count = $content->queue($content->pending($locale), $locale, auth()->user());

                    Notification::make()
                        ->success()
                        ->title($count === 0 ? 'Everything is already translated' : "Translating {$count} ".str('piece')->plural($count))
                        ->body($count === 0 ? null : 'They appear here as they finish, marked for review.')
                        ->send();
                }),
        ];
    }

    public function translateAction(): Action
    {
        return Action::make('translate')
            ->label(fn (array $arguments): string => ($arguments['status'] ?? null) === TranslateContent::STATUS_MISSING ? 'Translate' : 'Translate again')
            ->icon(Heroicon::OutlinedLanguage)
            ->link()
            ->visible(fn (): bool => $this->canTranslate())
            ->action(function (array $arguments, TranslateContent $content): void {
                $content->queue([(string) $arguments['key']], $this->locale, auth()->user());

                Notification::make()->success()->title('Translating')->body('It arrives as a draft for you to read through.')->send();
            });
    }

    public function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Mark as reviewed')
            ->icon(Heroicon::OutlinedCheck)
            ->link()
            ->action(function (array $arguments, TranslateContent $content): void {
                $content->approve((string) $arguments['key'], $this->locale, auth()->user());

                Notification::make()->success()->title('Reviewed')->body('It goes live the next time you publish.')->send();
            });
    }

    /**
     * Opens the page in this language with the live editor on, where the
     * translation is read and corrected in place.
     */
    public function editOnPageAction(): Action
    {
        return Action::make('editOnPage')
            ->label('Check on the page')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->link()
            ->action(function (array $arguments, EditingLock $lock): RedirectResponse {
                session([EditContext::SESSION_KEY => true]);

                if (auth()->user() !== null) {
                    $lock->acquire(auth()->user());
                }

                return redirect()->to(app(Locales::class)->url((string) $arguments['path'], $this->locale));
            });
    }
}
