<?php

namespace Gadya\Cms\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Gadya\Cms\Access\Abilities;
use Gadya\Cms\Filament\GadyaCmsPlugin;
use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\TranslateContent;
use Gadya\Cms\Localisation\Translations;
use Gadya\Cms\Localisation\Translator;
use Gadya\Cms\Models\Page;
use Illuminate\Database\Eloquent\Model;

/**
 * "Translate into Spanish" on a page, an article or an event. The
 * translation is written in the background into a draft marked for
 * review; nothing is published by it.
 */
class TranslateAction
{
    public static function make(string $name = 'translate'): Action
    {
        $locales = app(Locales::class);
        $additional = $locales->additional();

        return Action::make($name)
            ->label(count($additional) === 1 ? 'Translate into '.$locales->englishName($additional[0]) : 'Translate')
            ->icon(Heroicon::OutlinedLanguage)
            ->visible(fn (): bool => app(Locales::class)->isMultilingual()
                && GadyaCmsPlugin::get()->hasAi()
                && (auth()->user()?->can(Abilities::gate(Abilities::CONTENT)) ?? false)
                && app(Translator::class)->available())
            ->modalDescription('It is translated in the background and arrives as a draft marked for review. Nothing goes live until someone has read it and it is published.')
            ->schema(count($additional) > 1 ? [
                Select::make('locale')
                    ->label('Into')
                    ->options(collect($additional)->mapWithKeys(fn (string $code): array => [$code => $locales->name($code)])->all())
                    ->default($additional[0])
                    ->required(),
            ] : [])
            ->action(function (array $data, Model $record, TranslateContent $content): void {
                $locale = (string) ($data['locale'] ?? app(Locales::class)->additional()[0]);

                $content->queue([static::keyFor($record)], $locale, auth()->user());

                Notification::make()
                    ->success()
                    ->title('Translating into '.app(Locales::class)->englishName($locale))
                    ->body('It arrives as a draft for you to read through under Settings → Languages.')
                    ->send();
            });
    }

    public static function keyFor(Model $record): string
    {
        return $record instanceof Page ? 'pages.'.$record->slug : (string) app(Translations::class)->modelKey($record);
    }
}
