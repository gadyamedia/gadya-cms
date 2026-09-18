<?php

namespace Gadya\Cms\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Content\SiteBlocks;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The sections the client has saved to use again. Built from a page, not
 * here: this screen only renames and removes them, and says where each
 * one came from.
 *
 * @property-read Schema $form
 */
class Blocks extends Page
{
    protected string $view = 'gadya-cms::filament.pages.blocks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return GadyaCmsPlugin::get()->getContentNavigationGroup() ?? static::$navigationGroup;
    }

    protected static ?string $navigationLabel = 'Saved blocks';

    protected static ?string $title = 'Saved blocks';

    protected static ?int $navigationSort = 3;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getBlocksProperty(): array
    {
        return app(SiteBlocks::class)->all();
    }

    public function describe(string $key): string
    {
        $section = $this->blocks[$key]['section'] ?? [];
        $kind = Str::headline((string) ($section['type'] ?? 'section'));
        $items = count($section['items'] ?? $section['images'] ?? []);

        return $items > 0 ? "{$kind} · {$items} ".Str::plural('item', $items) : $kind;
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencil)
            ->schema([TextInput::make('label')->label('Name')->required()->maxLength(80)])
            ->fillForm(fn (array $arguments): array => ['label' => $this->blocks[$arguments['key']]['label'] ?? ''])
            ->action(function (array $arguments, array $data, SiteBlocks $blocks): void {
                $blocks->rename($arguments['key'], $data['label']);

                Notification::make()->success()->title('Renamed')->send();
            });
    }

    public function forgetAction(): Action
    {
        return Action::make('forget')
            ->label('Remove')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Pages already using this block keep their copy; it just stops being offered.')
            ->action(function (array $arguments, SiteBlocks $blocks): void {
                $blocks->forget($arguments['key']);

                Notification::make()->success()->title('Removed')->send();
            });
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false;
    }
}
