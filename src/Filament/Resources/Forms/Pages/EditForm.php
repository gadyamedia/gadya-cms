<?php

namespace Gadya\Cms\Filament\Resources\Forms\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Filament\Resources\Forms\FormBuilderSchema;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Models\Form;

class EditForm extends EditRecord
{
    protected static string $resource = FormResource::class;

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('Open its page')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn (Form $record): ?string => $record->publicUrl())
                ->openUrlInNewTab()
                ->visible(fn (Form $record): bool => $record->isLive() && $record->publicUrl() !== null),
            Action::make('stats')
                ->label('Figures')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(fn (Form $record): string => FormResource::getUrl('stats', ['record' => $record])),
            FormResource::shareAction()->color('gray'),
            FormResource::duplicateAction()->color('gray'),
            DeleteAction::make()->modalDescription('The form is removed from every page it is on. Enquiries already sent through it stay in the inbox.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['fields'] = FormBuilderSchema::toBuilder((array) ($data['fields'] ?? []));
        $data['settings'] = [...Form::defaultSettings(), ...array_filter((array) ($data['settings'] ?? []), fn ($value): bool => $value !== null)];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = SavesForms::prepare($data, $this);

        /** @var Form $form */
        $form = $this->record;

        if (($data['status'] ?? null) === Form::STATUS_PUBLISHED && $form->published_at === null) {
            $data['published_at'] = now();
        }

        if (($data['fields'] ?? null) !== $form->fields || ($data['messages'] ?? null) !== $form->messages) {
            $data['version'] = (int) $form->version + 1;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Form $form */
        $form = $this->record;

        if ($form->wasChanged('version')) {
            $form->recordVersion(auth()->id());
        }
    }
}
