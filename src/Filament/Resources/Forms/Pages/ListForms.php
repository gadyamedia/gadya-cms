<?php

namespace Gadya\Cms\Filament\Resources\Forms\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Forms\Builder\ConvertConfigForm;
use Gadya\Cms\Forms\Builder\FormGenerator;
use Gadya\Cms\Forms\Builder\FormTemplates;
use Illuminate\Support\HtmlString;
use Throwable;

class ListForms extends ListRecords
{
    protected static string $resource = FormResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter([
            $this->templateAction(),
            GadyaCmsPlugin::get()->hasAi() ? $this->describeAction() : null,
            $this->importAction(),
            CreateAction::make()->label('Start from scratch')->color('gray'),
        ]));
    }

    /** The starter forms: pick the nearest and change it. */
    protected function templateAction(): Action
    {
        $templates = app(FormTemplates::class)->all();

        return Action::make('fromTemplate')
            ->label('Start from a template')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->modalHeading('Start from a template')
            ->modalDescription('Pick the nearest to what you need. It starts as a draft for you to change.')
            ->modalSubmitActionLabel('Use this one')
            ->schema([
                Radio::make('template')
                    ->hiddenLabel()
                    ->options(array_map(fn (array $template): string => $template['title'], $templates))
                    ->descriptions(array_map(fn (array $template): string => $template['description'], $templates))
                    ->required(),
            ])
            ->action(function (array $data, FormTemplates $forms): void {
                $form = $forms->create($data['template']);

                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }

    /** "Describe your form" and the site's AI writes a draft. */
    protected function describeAction(): Action
    {
        return Action::make('describe')
            ->label('Describe your form')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (): bool => app(FormGenerator::class)->available())
            ->modalDescription('Say what the form is for and what you need to know. The AI writes a draft for you to check and change before it goes anywhere.')
            ->schema([
                Textarea::make('description')
                    ->label('What is the form for?')
                    ->placeholder('A catering order form: the date, how many people, which trays they want, any allergies, and whether we deliver.')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, FormGenerator $generator): void {
                try {
                    $form = $generator->generate($data['description']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('No form this time')->body($exception->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Drafted')->body('Read it through and change anything before you publish it.')->send();

                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }

    /**
     * A form a developer wrote into the site's settings, made editable
     * here. Same name and fields, so its enquiries and emails carry on;
     * the one in the settings keeps working until this is published.
     */
    protected function importAction(): Action
    {
        return Action::make('convert')
            ->label('Make a site form editable')
            ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
            ->color('gray')
            ->visible(fn (): bool => collect(app(ConvertConfigForm::class)->candidates())->contains(fn (array $candidate): bool => ! $candidate['converted']))
            ->modalDescription(new HtmlString('Copies a form that was set up in the site\'s code into the builder, as a draft. It keeps the same name and questions, so its enquiries, emails and figures carry on. The original keeps working until you publish the copy.'))
            ->schema([
                Select::make('form')
                    ->label('Form')
                    ->options(fn (): array => collect(app(ConvertConfigForm::class)->candidates())->reject(fn (array $candidate): bool => $candidate['converted'])->map(fn (array $candidate): string => $candidate['label'])->all())
                    ->required(),
            ])
            ->action(function (array $data, ConvertConfigForm $converter): void {
                try {
                    $form = $converter->convert($data['form']);
                } catch (Throwable $exception) {
                    Notification::make()->danger()->title('That form could not be copied')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Copied into the builder')->body('Check it, then publish it to take over from the original.')->send();

                $this->redirect(FormResource::getUrl('edit', ['record' => $form]));
            });
    }
}
