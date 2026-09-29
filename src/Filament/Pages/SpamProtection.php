<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Options\Options;
use UnitEnum;

/**
 * Cloudflare Turnstile's keys, for forms that ask for it. Every form
 * already has a hidden trap for bots and a time check; this is for the
 * one that still gets spam.
 *
 * @property-read Schema $form
 */
class SpamProtection extends Page
{
    protected string $view = 'gadya-cms::filament.pages.simple-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Spam protection';

    protected static ?string $title = 'Spam protection';

    protected static ?int $navigationSort = 9;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(Options $options): void
    {
        $this->form->fill(['site_key' => $options->get('forms.turnstile.site_key')]);
    }

    public function form(Schema $schema): Schema
    {
        $saved = app(Options::class)->getSecret('forms.turnstile.secret') !== null;

        return $schema
            ->components([
                Form::make([
                    Section::make('Cloudflare Turnstile')
                        ->description('A free check from Cloudflare that asks a robot to prove itself and a person nothing at all. Create a widget for this site in the Cloudflare dashboard under Turnstile, paste its two keys here, then switch it on for any form under its Settings.')
                        ->schema([
                            TextInput::make('site_key')->label('Site key')->maxLength(100),
                            TextInput::make('secret')->label('Secret key')->password()->placeholder($saved ? '•••••••• saved - leave empty to keep it' : '')->maxLength(100),
                        ])
                        ->columns(2),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
            ])
            ->statePath('data');
    }

    public function save(Options $options): void
    {
        $state = $this->form->getState();

        $options->set('forms.turnstile.site_key', trim((string) ($state['site_key'] ?? '')) ?: null);

        if (filled($state['secret'] ?? null)) {
            $options->setSecret('forms.turnstile.secret', trim((string) $state['secret']));
        }

        Notification::make()->success()->title('Saved')->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
