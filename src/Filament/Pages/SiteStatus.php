<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Services\SchedulePublish;
use Gadya\Cms\Support\Maintenance;
use UnitEnum;

/**
 * Whether the site is open, and when the next publish goes out.
 *
 * @property-read Schema $form
 */
class SiteStatus extends Page
{
    protected string $view = 'gadya-cms::filament.pages.site-status';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Coming soon mode';

    protected static ?string $title = 'Coming soon mode';

    protected static ?int $navigationSort = 6;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(Maintenance $maintenance): void
    {
        $this->form->fill([
            'enabled' => $maintenance->isOn(),
            'heading' => $maintenance->heading(),
            'message' => $maintenance->message(),
            'until' => $maintenance->until(),
            'password' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('While the site is closed')
                        ->description('Visitors see a short notice instead of the site. You and anyone else who can sign in still see it as normal, so you can keep working.')
                        ->schema([
                            Toggle::make('enabled')
                                ->label('Close the site to visitors')
                                ->live()
                                ->columnSpanFull(),
                            TextInput::make('heading')
                                ->label('Heading')
                                ->maxLength(120)
                                ->required(fn (Get $get): bool => (bool) $get('enabled')),
                            DateTimePicker::make('until')
                                ->label('Opens again')
                                ->seconds(false)
                                ->helperText('Optional. The site opens itself, and the notice says when.'),
                            Textarea::make('message')
                                ->label('What it says')
                                ->rows(3)
                                ->maxLength(500)
                                ->columnSpanFull(),
                            TextInput::make('password')
                                ->label('Password for a sneak peek')
                                ->maxLength(60)
                                ->autocomplete('off')
                                ->placeholder(fn (Maintenance $maintenance): string => $maintenance->hasPassword() ? 'A password is set. Type a new one to change it.' : 'Leave blank for nobody but you')
                                ->helperText('Anyone with this can look around. Share the link below rather than the password itself.')
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                        ])
                        ->columns(2),
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

    public function save(Maintenance $maintenance): void
    {
        $data = $this->form->getState();

        $maintenance->save([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'heading' => $data['heading'] ?? null,
            'message' => $data['message'] ?? null,
            'until' => isset($data['until']) ? (string) $data['until'] : null,
            ...(filled($data['password'] ?? null) ? ['password' => $data['password']] : []),
        ]);

        $this->form->fill([...$data, 'password' => null]);

        Notification::make()
            ->success()
            ->title($maintenance->isOn() ? 'The site is closed to visitors' : 'The site is open')
            ->send();
    }

    public function getShareUrlProperty(): ?string
    {
        return app(Maintenance::class)->shareUrl();
    }

    public function getScheduledPublishProperty(): ?string
    {
        $schedule = app(SchedulePublish::class);

        return $schedule->isPending() ? $schedule->at()?->format('l j F Y, g:ia') : null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }
}
