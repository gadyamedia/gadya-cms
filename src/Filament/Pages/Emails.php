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
use Gadya\Cms\Forms\AutoReplies;
use Gadya\Cms\Forms\FormDefinition;
use Gadya\Cms\Mail\SharedSender;
use Gadya\Cms\Notifications\TestEmail;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;
use UnitEnum;

/**
 * Who sends the site's email, and what it says back to someone who fills
 * in a form.
 *
 * @property-read Schema $form
 */
class Emails extends Page
{
    protected string $view = 'gadya-cms::filament.pages.emails';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Automatic replies';

    protected static ?string $title = 'Automatic replies';

    protected static ?int $navigationSort = 7;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(AutoReplies $replies): void
    {
        $this->form->fill(['replies' => $replies->all()]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (FormDefinition::labels() as $name => $label) {
            $sections[] = Section::make($label)
                ->description('Sent to whoever filled this form in, as soon as they send it. Only sent when the form asks for an email address.')
                ->schema([
                    Toggle::make("replies.{$name}.enabled")
                        ->label('Send a reply')
                        ->columnSpanFull(),
                    TextInput::make("replies.{$name}.subject")
                        ->label('Subject')
                        ->maxLength(150)
                        ->columnSpanFull(),
                    Textarea::make("replies.{$name}.body")
                        ->label('Message')
                        ->rows(8)
                        ->maxLength(2000)
                        ->helperText('Use {{ name }} for their name, {{ business }} for yours, or the name of any field on the form. A blank line starts a new paragraph.')
                        ->columnSpanFull(),
                ])
                ->collapsible();
        }

        return $schema
            ->components([
                $this->sendingSection(),
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

    /**
     * Where the site's email comes from, and a way to prove it arrives.
     */
    private function sendingSection(): Section
    {
        $sender = app(SharedSender::class);

        return Section::make('How this site sends email')
            ->description($sender->enabled()
                ? 'Your email is sent by Gadya Media, from '.$sender->address().'. There is nothing to set up, and nothing to pay for. Replies go to '.($sender->replyTo() ?? 'whoever the message is about').'.'
                : 'Your email is sent by this site\'s own mail service ('.config('mail.default').'), from '.(config('mail.from.address') ?: 'no address yet').'.')
            ->schema([]);
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send me a test email')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action(fn () => $this->sendTest()),
        ];
    }

    public function sendTest(): void
    {
        $user = auth()->user();
        $address = (string) ($user?->email ?? '');

        if ($address === '') {
            Notification::make()->danger()->title('Your account has no email address')->send();

            return;
        }

        try {
            Notifier::route('mail', $address)->notify(new TestEmail((string) ($user?->name ?: 'someone')));
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('The test email could not be sent')
                ->body($exception->getMessage())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()->success()->title('Sent to '.$address)->body('If it has not arrived in a few minutes, look in the junk folder.')->send();
    }

    public function save(AutoReplies $replies): void
    {
        $replies->save((array) ($this->form->getState()['replies'] ?? []));

        Notification::make()->success()->title('Saved')->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::SETTINGS)) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return FormDefinition::labels() !== [] || app(SharedSender::class)->enabled();
    }
}
