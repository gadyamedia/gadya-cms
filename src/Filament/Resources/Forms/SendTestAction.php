<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Forms\Builder\FormTestSender;
use Gadya\Cms\Forms\Builder\TestReport;
use Gadya\Cms\Livewire\SenderPanelView;
use Gadya\Cms\Sms\TextAlerts;

/**
 * "Send a test" for a form's emails and texts, in a small modal: who gets
 * it, whether to try the reply to the visitor and the text alert too, and
 * - for a form with routing rules - which answer to pretend was given.
 * It sits on a built form's Emails and texts tab and on each configured
 * form's section of Settings → Enquiry emails, and reads the form as it
 * is on screen, so a draft can be tested before it is saved.
 *
 * Nothing it does is kept: see FormTestSender.
 */
final class SendTestAction
{
    /**
     * @param  string|null  $configuredForm  The name of a form configured in code; null for the form being edited
     */
    public static function make(?string $configuredForm = null): Action
    {
        return Action::make($configuredForm === null ? 'sendFormTest' : 'sendFormTest_'.$configuredForm)
            ->label('Send a test')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->modalHeading('Send a test')
            ->modalDescription('This sends the real email, marked as a test, using the form as it is on screen now. Nothing is saved and nothing else happens.')
            ->modalSubmitActionLabel('Send the test')
            ->fillForm(fn ($livewire): array => [
                'to' => self::hasListed($livewire, $configuredForm) ? FormTestSender::EVERYONE : FormTestSender::ONLY_ME,
                'address' => null,
                'rule' => null,
                'reply' => false,
                'text' => false,
                'numbers' => [],
            ])
            ->schema(fn ($livewire): array => self::fields($livewire, $configuredForm))
            ->action(function (array $data, $livewire) use ($configuredForm): void {
                $choices = [
                    'to' => (string) ($data['to'] ?? FormTestSender::EVERYONE),
                    'address' => $data['address'] ?? null,
                    'me' => auth()->user()?->email,
                    'rule' => $data['rule'] ?? null,
                    'reply' => (bool) ($data['reply'] ?? false),
                    'text' => (bool) ($data['text'] ?? false),
                    'numbers' => (array) ($data['numbers'] ?? []),
                ];

                $sender = app(FormTestSender::class);

                self::report($configuredForm === null
                    ? $sender->built(FormEditSchema::formFromScreen($livewire), $choices)
                    : $sender->configured($configuredForm, (array) data_get($livewire, 'data', []), $choices));

                $livewire->dispatch(SenderPanelView::SENT);
            });
    }

    /**
     * What a test said, on screen until it is dismissed when it did not
     * all go: a failure is not something to glance at and miss.
     */
    public static function report(TestReport $report): void
    {
        $notification = Notification::make()->title($report->title())->body($report->body());

        match (true) {
            $report->succeeded() => $notification->success(),
            $report->partly() => $notification->warning()->persistent(),
            default => $notification->danger()->persistent(),
        };

        $notification->send();
    }

    /**
     * @return list<mixed>
     */
    private static function fields(mixed $livewire, ?string $configuredForm): array
    {
        $routes = self::routes($livewire, $configuredForm);
        $hasReply = self::replyIsOn($livewire, $configuredForm);
        $texting = $configuredForm === null && app(TextAlerts::class)->enabled();

        return [
            View::make('gadya-cms::filament.mail.sender-panel-live')
                ->viewData(['limit' => 3]),
            Radio::make('to')
                ->label('Who gets it?')
                ->options([
                    FormTestSender::EVERYONE => 'Send to everyone on the list',
                    FormTestSender::ONLY_ME => 'Send only to me ('.(auth()->user()?->email ?: 'no address').')',
                    FormTestSender::THIS_ADDRESS => 'Send to this address',
                ])
                ->live()
                ->required(),
            TextInput::make('address')
                ->label('Address')
                ->email()
                ->maxLength(190)
                ->required(fn (Get $get): bool => $get('to') === FormTestSender::THIS_ADDRESS)
                ->visible(fn (Get $get): bool => $get('to') === FormTestSender::THIS_ADDRESS),
            Select::make('rule')
                ->label('Pretend the answer makes a rule fire')
                ->placeholder('No rule - use the usual list')
                ->options($routes)
                ->helperText('The test says which rule fired, and the rule\'s people get it.')
                ->visible($routes !== []),
            Toggle::make('reply')
                ->label('Also test the reply to the visitor')
                ->helperText($hasReply
                    ? 'Sent to the address chosen above (or to you), never to a real visitor.'
                    : 'The reply is switched off for this form, so visitors are not sent one yet. The test shows what it would say.'),
            Toggle::make('text')
                ->label('Also send the text alert')
                ->live()
                ->visible($texting),
            TagsInput::make('numbers')
                ->label('Mobile numbers to text')
                ->placeholder('Add a mobile number')
                ->helperText('Leave blank to text the form\'s own numbers (only when sending to everyone).')
                ->visible(fn (Get $get): bool => $texting && (bool) $get('text')),
        ];
    }

    /**
     * The rules on screen, as "Service is Catering", by their place in
     * the list.
     *
     * @return array<int, string>
     */
    private static function routes(mixed $livewire, ?string $configuredForm): array
    {
        if ($configuredForm !== null) {
            return [];
        }

        $form = FormEditSchema::formFromScreen($livewire);
        $sender = app(FormTestSender::class);
        $options = [];

        foreach (array_values((array) $form->setting('routes', [])) as $position => $route) {
            if (is_array($route) && filled($route['field'] ?? null)) {
                $options[$position] = $sender->describeRule($form, $route);
            }
        }

        return $options;
    }

    private static function hasListed(mixed $livewire, ?string $configuredForm): bool
    {
        if ($configuredForm !== null) {
            return array_filter((array) data_get($livewire, "data.notify.{$configuredForm}", [])) !== []
                || array_filter((array) config("gadya-cms.forms.forms.{$configuredForm}.notify", [])) !== [];
        }

        return array_filter((array) FormEditSchema::formFromScreen($livewire)->setting('notify', [])) !== [];
    }

    private static function replyIsOn(mixed $livewire, ?string $configuredForm): bool
    {
        return $configuredForm !== null
            ? (bool) data_get($livewire, "data.replies.{$configuredForm}.enabled", false)
            : (bool) FormEditSchema::formFromScreen($livewire)->setting('autoreply.enabled');
    }
}
