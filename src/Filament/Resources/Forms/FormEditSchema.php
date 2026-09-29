<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Gadya\Cms\Forms\Builder\FormLogic;
use Gadya\Cms\Forms\Builder\FormRenderer;
use Gadya\Cms\Forms\Builder\SpamGuard;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * A form's edit screen: the questions beside a live preview, then what it
 * says after sending, who hears about it, where else it goes, and the
 * switches.
 */
final class FormEditSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('form')
                ->persistTabInQueryString()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Questions')->icon('heroicon-o-queue-list')->schema([
                        Grid::make(['default' => 1, 'xl' => 5])->schema([
                            Group::make([FormBuilderSchema::builder()])->columnSpan(['default' => 1, 'xl' => 3]),
                            Section::make('Preview')
                                ->description('How it looks on the site. Questions with rules show and hide as you answer.')
                                ->schema([
                                    View::make('gadya-cms::filament.forms.preview')
                                        ->viewData(fn ($livewire): array => ['html' => self::preview($livewire)]),
                                ])
                                ->columnSpan(['default' => 1, 'xl' => 2]),
                        ]),
                    ]),
                    Tab::make('Details')->icon('heroicon-o-pencil-square')->schema(self::details()),
                    Tab::make('After sending')->icon('heroicon-o-check-badge')->schema(self::afterSending()),
                    Tab::make('Emails and texts')->icon('heroicon-o-envelope')->schema(FormNotificationSchema::fields()),
                    Tab::make('Webhooks')->icon('heroicon-o-bolt')->schema(self::webhooks()),
                    Tab::make('Settings')->icon('heroicon-o-cog-6-tooth')->schema(self::settings()),
                ]),
        ]);
    }

    /**
     * The form as it stands on screen, drawn as a visitor would see it.
     */
    public static function preview(mixed $livewire): HtmlString
    {
        $data = (array) data_get($livewire, 'data', []);

        $form = new Form([
            'slug' => (string) (($data['slug'] ?? '') ?: 'preview'),
            'title' => (string) ($data['title'] ?? ''),
            'fields' => FormBuilderSchema::fromBuilder((array) ($data['fields'] ?? [])),
            'messages' => (array) ($data['messages'] ?? []),
            'settings' => (array) ($data['settings'] ?? []),
        ]);

        return rescue(
            fn (): HtmlString => app(FormRenderer::class)->render($form, ['preview' => true]),
            new HtmlString('<p>The preview will appear once the form has a question.</p>'),
            report: false,
        );
    }

    /**
     * @return list<mixed>
     */
    private static function details(): array
    {
        return [
            TextInput::make('title')
                ->label('Name')
                ->helperText('Shown on the form\'s own page and in the enquiries inbox.')
                ->required()
                ->maxLength(160)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, ?string $state, ?Form $record): void {
                    if ($record === null && blank($get('slug'))) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')
                ->label('Address')
                ->prefix(url('/'.trim((string) config('gadya-cms.forms.builder.path', 'forms'), '/')).'/')
                ->helperText('Also the name its enquiries are filed under. Keep it short.')
                ->required()
                ->maxLength(80)
                ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*\z/')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('site_id', app(SiteContext::class)->id())),
            Textarea::make('description')->label('A line about it')->helperText('Shown above the form on its own page.')->rows(2)->maxLength(500)->columnSpanFull(),
            Select::make('status')
                ->options([
                    Form::STATUS_DRAFT => 'Draft - not shown anywhere yet',
                    Form::STATUS_PUBLISHED => 'Live - shown wherever it is placed',
                    Form::STATUS_ARCHIVED => 'Archived - put away',
                ])
                ->default(Form::STATUS_DRAFT)
                ->selectablePlaceholder(false)
                ->required(),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function afterSending(): array
    {
        $message = fn (string $key, string $label, bool $long = false) => ($long ? Textarea::make("messages.{$key}")->rows(3) : TextInput::make("messages.{$key}"))
            ->label($label)
            ->placeholder(Form::defaultMessages()[$key] ?? '')
            ->maxLength($long ? 1000 : 120);

        return [
            Section::make('The thank-you')->schema([
                $message('success', 'What they see once it is sent', true)->columnSpanFull(),
                TextInput::make('settings.redirect')
                    ->label('Or take them to a page instead')
                    ->placeholder('/thank-you')
                    ->helperText('A page on this site, starting with /. Leave empty to show the message.')
                    ->rule('regex:/^\/(?!\/)/')
                    ->maxLength(255),
            ])->columns(2),
            Section::make('Buttons and steps')->schema([
                $message('submit', 'Send button'),
                $message('next', 'Next button'),
                $message('back', 'Back button'),
                $message('first_step', 'Title of the first step'),
            ])->columns(2),
            Section::make('Finishing later')->schema([
                Toggle::make('settings.local_progress')->label('Keep what they type on their device if they leave the page')->default(true),
                Toggle::make('settings.save_later')->label('Offer "email me a link to finish later"')->live(),
                $message('save_later', 'The link to finish later')->visible(fn (Get $get): bool => (bool) $get('settings.save_later')),
                $message('save_later_intro', 'What it says', true)->visible(fn (Get $get): bool => (bool) $get('settings.save_later')),
            ])->columns(2),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function webhooks(): array
    {
        return [
            Section::make('Send each enquiry to another service')
                ->description('For Zapier, Make or your own system: every enquiry is posted to each address as JSON, and tried again if it does not answer. With a secret, each request carries an X-Gadya-Signature header (sha256= and an HMAC of the body).')
                ->schema([
                    Repeater::make('settings.webhooks')
                        ->hiddenLabel()
                        ->schema([
                            TextInput::make('url')->label('Address')->url()->required()->maxLength(500)->placeholder('https://hooks.zapier.com/...')->columnSpan(2),
                            TextInput::make('secret')->label('Secret (optional)')->password()->revealable()->maxLength(200),
                            Toggle::make('active')->label('On')->default(true)->inline(false),
                        ])
                        ->columns(4)
                        ->addActionLabel('Add a webhook')
                        ->defaultItems(0),
                ]),
        ];
    }

    /**
     * @return list<mixed>
     */
    private static function settings(): array
    {
        return [
            Section::make('Where it can be seen')->schema([
                Toggle::make('settings.public_page')->label('Give it a page of its own')->helperText('A link to put in a text, an email or a QR code.')->default(true),
                Toggle::make('settings.noindex')->label('Keep that page out of Google')->default(true),
            ])->columns(2),
            Section::make('Look')->schema([
                Toggle::make('settings.styles')->label('Use the simple built-in look')->helperText('Coloured from Look & feel. Turn off if the site styles its forms itself.')->default(true),
                Toggle::make('settings.progress')->label('Show a progress bar on forms with steps')->default(true),
                TextInput::make('settings.css_class')->label('Extra CSS class')->helperText('For a developer: added to the form, to style it.')->maxLength(120)->rule('regex:/^[A-Za-z0-9_\- ]*$/'),
            ])->columns(2),
            Section::make('Spam and counting')->schema([
                Toggle::make('settings.turnstile')
                    ->label('Ask Cloudflare Turnstile to check for robots')
                    ->helperText(fn (): string => app(SpamGuard::class)->turnstileConfigured()
                        ? 'The keys are set under Settings → Spam protection.'
                        : 'Add the Turnstile keys under Settings → Spam protection first.')
                    ->disabled(fn (): bool => ! app(SpamGuard::class)->turnstileConfigured()),
                Select::make('settings.analytics_event')
                    ->label('Count it on the dashboard as')
                    ->options(fn (): array => collect((array) config('gadya-cms.analytics.events', []))->mapWithKeys(fn (string $event): array => [$event => Str::headline($event)])->all())
                    ->placeholder('Not counted on the dashboard')
                    ->default('lead_form_submit'),
                Toggle::make('settings.callback')
                    ->label('This is a call-back form')
                    ->helperText('A ticked consent box is sent to the Gadya portal as consent to be rung back, perhaps by the AI receptionist.'),
            ])->columns(2),
        ];
    }

    /** @return array<string, string> */
    public static function operators(): array
    {
        return FormLogic::operatorLabels();
    }
}
