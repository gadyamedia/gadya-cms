<?php

namespace Gadya\Cms\Filament\Resources\Submissions\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\Resources\Submissions\SubmissionResource;
use Gadya\Cms\Forms\Builder\FormExport;
use Gadya\Cms\Forms\FormDefinition;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSubmissions extends ListRecords
{
    protected static string $resource = SubmissionResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportForm')
                ->label('Download a form\'s enquiries')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->modalDescription('Every enquiry from one form, a column for each question.')
                ->schema([
                    Select::make('form')->label('Form')->options(fn (): array => FormDefinition::labels())->required(),
                    Radio::make('format')
                        ->label('As')
                        ->options(array_filter(['csv' => 'CSV (opens in anything)', 'xlsx' => FormExport::canWriteExcel() ? 'Excel' : null]))
                        ->default('csv')
                        ->required(),
                ])
                ->action(fn (array $data, FormExport $export): StreamedResponse => $export->download(
                    SubmissionResource::getEloquentQuery()->where('form', $data['form'])->orderBy('created_at')->get(),
                    (FormDefinition::labels()[$data['form']] ?? $data['form']).' enquiries',
                    (string) $data['format'],
                )),
        ];
    }
}
