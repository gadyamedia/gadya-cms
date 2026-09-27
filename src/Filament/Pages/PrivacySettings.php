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
        $this->form->fill($consent->draft());
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
                        ->schema([
                            TextInput::make('heading')->required()->maxLength(80),
                            Textarea::make('message')->required()->rows(3)->maxLength(600),
                            Textarea::make('categories.necessary')->label('Necessary')->rows(2)->maxLength(300),
                            Textarea::make('categories.analytics')->label('Analytics')->rows(2)->maxLength(300),
                            Textarea::make('categories.marketing')->label('Marketing')->rows(2)->maxLength(300),
                        ])
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

        $repository->saveDraft([
            ...$repository->draft(),
            Consent::KEY => [
                'banner_enabled' => (bool) ($data['banner_enabled'] ?? false),
                'honour_gpc' => (bool) ($data['honour_gpc'] ?? true),
                'policy_url' => filled($data['policy_url'] ?? null) ? (string) $data['policy_url'] : null,
                'heading' => (string) ($data['heading'] ?? ''),
                'message' => (string) ($data['message'] ?? ''),
                'categories' => array_map('strval', array_intersect_key((array) ($data['categories'] ?? []), array_flip(Consent::categories()))),
            ],
        ]);

        Notification::make()->success()->title('Saved to your draft')->body('Publish to change the banner on the site.')->send();
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
