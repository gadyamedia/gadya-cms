<?php

namespace Gadya\Cms\Filament\Schemas;

use Filament\Forms\Components\Select;
use Gadya\Cms\Models\Media;

/**
 * A picker over the site's photo library. The document stores the
 * filename - never a URL - so an image can be re-processed or moved
 * without every page that uses it going stale.
 */
class MediaSelect
{
    public static function make(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (?string $state): array => static::options($state))
            ->searchable()
            /*
             * A searchable select loads its options on demand, so without
             * this it has no label for the value it was given and renders
             * empty - the photo is still set, it just looks as though it
             * is not.
             */
            ->getOptionLabelUsing(fn (?string $value): ?string => $value === null || $value === ''
                ? null
                : (static::options($value)[$value] ?? $value))
            ->nullable();
    }

    /**
     * An image that shipped with the site may not have been indexed in the
     * library yet. Offering the value already on the page keeps the form
     * from rejecting content it did not write.
     *
     * @return array<string, string>
     */
    protected static function options(?string $state): array
    {
        $library = Media::query()
            ->ready()
            ->orderBy('original_name')
            ->pluck('original_name', 'filename')
            ->all();

        if ($state !== null && $state !== '' && ! array_key_exists($state, $library)) {
            $library[$state] = $state;
        }

        return $library;
    }
}
