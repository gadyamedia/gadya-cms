<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Filament\Actions\PublishChangesAction;
use Gadya\Cms\Privacy\Consent;
use UnitEnum;

/**
 * The privacy banner: whether it shows, what it says, and where the
 * privacy policy is. Saved to the draft and made live with Publish, like
 * every other word on the site.
 *
 * @property-read Schema $form
 */
class PrivacySettings extends Page
{
    protected string $view = 'gadya-cms::filament.pages.privacy-settings';

    protected static ?string $slug = 'privacy-choices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Privacy choices';

    protected static ?string $title = 'Privacy choices';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(Consent $consent): void
    {
        $draft = $consent->draft();
        $stored = $consent->storedDraft();

        /*
         * The switches as they stand; the words only as she wrote them, so
         * a blank field keeps following the default (and its translation)
         * instead of freezing today's English into the document.
         */
        $this->form->fill([
            'banner_enabled' => $draft['banner_enabled'],
            'honour_gpc' => $draft['honour_gpc'],
            'policy_url' => $stored['policy_url'] ?? null,
            'text' => (array) ($stored['text'] ?? []),
            'categories' => (array) ($stored['categories'] ?? []),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('The banner')
                        ->description('Asks visitors before analytics and advertising tags run, as the New Jersey Data Privacy Act expects of a site that uses them. Tags only wait if they are wrapped in <x-gadya-cms::consented-script>; see the privacy guide.')
                        ->schema([
                            Toggle::make('banner_enabled')
                                ->label('Show the privacy banner')
                                ->helperText('Visitors choose once: accept all, reject all, or pick. "Your privacy choices" at the foot of the page reopens it.'),
                            Toggle::make('honour_gpc')
                                ->label('Honour Global Privacy Control')
                                ->helperText('A browser that sends the signal is treated as a "no" to marketing: an opt-out of the sale of data and targeted advertising. Leave on; New Jersey requires it.'),
                            TextInput::make('policy_url')
                                ->label('Privacy policy address')
                                ->placeholder('/privacy-policy')
                                ->maxLength(500)
                                ->rule('regex:#^(/|https?://)#i')
                                ->validationMessages(['regex' => 'Start with / for a page on this site, or https:// for one elsewhere.']),
                        ]),
                    Section::make('What it says')
                        ->description('Every word on the banner. Leave a box empty to keep the wording shown in it.')
                        ->schema(static::textFields())
                        ->columns(2)
                        ->collapsible(),
                    Section::make('The choices')
                        ->description('What each kind of cookie is called, and what it is for.')
                        ->schema(static::categoryFields())
                        ->columns(2)
                        ->collapsible(),
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
        $written = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $text = [];

        foreach (array_keys(Consent::defaults()['text']) as $key) {
            $text[$key] = $written($data['text'][$key] ?? null);
        }

        $categories = [];

        foreach (Consent::categories() as $category) {
            $categories[$category] = [
                'name' => $written($data['categories'][$category]['name'] ?? null),
                'description' => $written($data['categories'][$category]['description'] ?? null),
            ];
        }

        $repository->saveDraft([
            ...$repository->draft(),
            Consent::KEY => [
                'banner_enabled' => (bool) ($data['banner_enabled'] ?? false),
                'honour_gpc' => (bool) ($data['honour_gpc'] ?? true),
                'policy_url' => $written($data['policy_url'] ?? null),
                'text' => array_filter($text),
                'categories' => array_filter(array_map('array_filter', $categories)),
            ],
        ]);

        Notification::make()->success()->title('Saved to your draft')->body('Publish to change the banner on the site.')->send();
    }

    /**
     * @return list<TextInput|Textarea>
     */
    protected static function textFields(): array
    {
        $defaults = Consent::defaults()['text'];
        $labels = [
            'heading' => 'Heading',
            'message' => 'Message',
            'accept' => '"Accept all" button',
            'reject' => '"Reject all" button',
            'choose' => '"Choose" button',
            'save' => '"Save my choices" button',
            'legend' => 'Above the choices',
            'policy' => 'Privacy policy link',
            'link' => 'Link that reopens the banner',
            'gpc' => 'When the browser sends Global Privacy Control',
        ];

        $fields = [];

        foreach ($labels as $key => $label) {
            $fields[] = in_array($key, ['message', 'gpc'], true)
                ? Textarea::make("text.{$key}")->label($label)->placeholder($defaults[$key])->rows(3)->maxLength(600)->columnSpanFull()
                : TextInput::make("text.{$key}")->label($label)->placeholder($defaults[$key])->maxLength(120);
        }

        return $fields;
    }

    /**
     * @return list<TextInput|Textarea>
     */
    protected static function categoryFields(): array
    {
        $fields = [];

        foreach (Consent::defaults()['categories'] as $category => $default) {
            $fields[] = TextInput::make("categories.{$category}.name")->label(ucfirst($category).' - name')->placeholder($default['name'])->maxLength(60);
            $fields[] = Textarea::make("categories.{$category}.description")->label(ucfirst($category).' - what it is for')->placeholder($default['description'])->rows(2)->maxLength(300);
        }

        return $fields;
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [PublishChangesAction::make()];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
