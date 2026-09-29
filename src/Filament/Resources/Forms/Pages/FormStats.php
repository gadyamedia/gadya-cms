<?php

namespace Gadya\Cms\Filament\Resources\Forms\Pages;

use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Gadya\Cms\Filament\Resources\Forms\FormResource;
use Gadya\Cms\Models\Form;

/**
 * How one form is doing: seen, started, sent, where people give up, how
 * long it takes, and where they came from.
 */
class FormStats extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FormResource::class;

    protected string $view = 'gadya-cms::filament.forms.stats';

    public int $days = 30;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        /** @var Form $form */
        $form = $this->record;

        return 'Figures for '.$form->title;
    }

    public function setRange(int $days): void
    {
        $this->days = in_array($days, [7, 30, 90], true) ? $days : 30;
    }
}
