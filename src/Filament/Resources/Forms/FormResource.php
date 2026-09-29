<?php

namespace Gadya\Cms\Filament\Resources\Forms;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\Actions\TranslateAction;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Filament\Resources\Forms\Pages\CreateForm;
use Gadya\Cms\Filament\Resources\Forms\Pages\EditForm;
use Gadya\Cms\Filament\Resources\Forms\Pages\FormStats;
use Gadya\Cms\Filament\Resources\Forms\Pages\ListForms;
use Gadya\Cms\Forms\Builder\FormTemplates;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Content → Forms: the forms the client builds herself, and places on any
 * page as a "Form" section.
 */
class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Forms';

    protected static ?string $modelLabel = 'form';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    public static function form(Schema $schema): Schema
    {
        return FormEditSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Form $record): string => '/'.trim((string) config('gadya-cms.forms.builder.path', 'forms'), '/').'/'.$record->slug),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Form::STATUS_PUBLISHED => 'Live',
                        Form::STATUS_ARCHIVED => 'Archived',
                        default => 'Draft',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Form::STATUS_PUBLISHED => 'success',
                        Form::STATUS_ARCHIVED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('submissions_count')->label('Enquiries')->counts('submissions')->sortable(),
                TextColumn::make('updated_at')->label('Changed')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Form::STATUS_DRAFT => 'Draft',
                    Form::STATUS_PUBLISHED => 'Live',
                    Form::STATUS_ARCHIVED => 'Archived',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('stats')
                    ->label('Figures')
                    ->icon(Heroicon::OutlinedChartBar)
                    ->url(fn (Form $record): string => static::getUrl('stats', ['record' => $record])),
                static::shareAction(),
                static::duplicateAction(),
                static::archiveAction(),
                TranslateAction::make(),
                DeleteAction::make()
                    ->modalDescription('The form is removed from every page it is on. Enquiries already sent through it stay in the inbox.'),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /** Where the form can be found and how to put it elsewhere. */
    public static function shareAction(): Action
    {
        return Action::make('share')
            ->label('Share')
            ->icon(Heroicon::OutlinedShare)
            ->modalHeading(fn (Form $record): string => 'Share "'.$record->title.'"')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->modalContent(fn (Form $record): View => view('gadya-cms::filament.forms.share', ['form' => $record]));
    }

    /** A copy to change, as a draft, so a half-made copy is never live. */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplicate')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalDescription('The copy is a draft until you publish it.')
            ->action(function (Form $record, $livewire): void {
                $copy = $record->replicate(['version', 'published_at', 'submissions_count']);
                $copy->fill([
                    'title' => $record->title.' (copy)',
                    'slug' => app(FormTemplates::class)->availableSlug($record->slug.'-copy'),
                    'status' => Form::STATUS_DRAFT,
                    'version' => 1,
                    'created_by' => auth()->id(),
                ])->save();

                Notification::make()->success()->title('Copied')->body('The copy is a draft.')->send();

                $livewire->redirect(static::getUrl('edit', ['record' => $copy]));
            });
    }

    /**
     * Put away, not deleted: its enquiries and figures stay, and it can be
     * brought back. An archived form shows nothing where it was placed.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(fn (Form $record): string => $record->status === Form::STATUS_ARCHIVED ? 'Bring back' : 'Archive')
            ->icon(fn (Form $record): Heroicon => $record->status === Form::STATUS_ARCHIVED ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedArchiveBox)
            ->requiresConfirmation(fn (Form $record): bool => $record->status !== Form::STATUS_ARCHIVED)
            ->modalDescription('It disappears from every page it is on. Its enquiries and figures are kept, and you can bring it back.')
            ->action(fn (Form $record) => $record->update([
                'status' => $record->status === Form::STATUS_ARCHIVED ? Form::STATUS_DRAFT : Form::STATUS_ARCHIVED,
            ]));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('site_id', app(SiteContext::class)->id());
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::FORMS)) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListForms::route('/'),
            'create' => CreateForm::route('/create'),
            'edit' => EditForm::route('/{record}/edit'),
            'stats' => FormStats::route('/{record}/figures'),
        ];
    }
}
