<?php

namespace Gadya\Cms\Forms\Builder;

use Gadya\Cms\Forms\FormLocale;
use Gadya\Cms\Localisation\Locales;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Lang;

/**
 * The words a site keeps in its `lang/` files, for a form being converted
 * from a template that writes `__('contact.name')` where a label would be.
 *
 * Every language the site has files for - `lang/ru/contact.php` and
 * `lang/ru.json` alike - is read through Laravel's own translator, with
 * no falling back to another language: a key Russian does not have is
 * left for the English to stand in for, as the CMS's overlay does.
 */
class LangFiles
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly Locales $locales,
    ) {}

    /**
     * The languages the site has words for, the CMS's default first.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $path = lang_path();
        $found = [];

        if ($this->files->isDirectory($path)) {
            foreach ($this->files->directories($path) as $directory) {
                $found[] = basename($directory);
            }

            foreach ($this->files->glob($path.'/*.json') ?: [] as $file) {
                $found[] = basename($file, '.json');
            }
        }

        $found = array_values(array_unique(array_filter($found, fn (string $locale): bool => $locale !== 'vendor' && FormLocale::normalise($locale) !== null)));
        $default = $this->locales->default();

        usort($found, fn (string $a, string $b): int => [FormLocale::normalise($a) !== $default, $a] <=> [FormLocale::normalise($b) !== $default, $b]);

        return $found;
    }

    /** The words behind a key in one language, or null when that language does not have it. */
    public function get(string $key, string $locale): ?string
    {
        $value = Lang::get($key, [], $locale, false);

        return is_string($value) && $value !== $key && trim($value) !== '' ? $value : null;
    }

    /**
     * A key in every language that has it.
     *
     * @return array<string, string>
     */
    public function everywhere(string $key): array
    {
        $words = [];

        foreach ($this->locales() as $locale) {
            $value = $this->get($key, $locale);

            if ($value !== null) {
                $words[FormLocale::normalise($locale) ?? $locale] = $value;
            }
        }

        return $words;
    }

    /**
     * The translation key in a piece of Blade - `{{ __('contact.name') }}`,
     * `@lang('contact.name')`, `{{ trans('contact.name') }}` - when that is
     * all it says.
     */
    public static function keyIn(?string $blade): ?string
    {
        if (! is_string($blade)) {
            return null;
        }

        $pattern = '/^\s*(?:\{\{\s*|\{!!\s*)?(?:__|trans|@lang|Lang::get)\(\s*([\'"])((?:(?!\1).)+)\1\s*(?:,[^)]*)?\)\s*(?:\}\}|!!\})?\s*$/s';

        return preg_match($pattern, $blade, $match) === 1 ? $match[2] : null;
    }

    /**
     * Every translation key anywhere in a piece of Blade or PHP.
     *
     * @return list<string>
     */
    public static function keysIn(string $source): array
    {
        preg_match_all('/(?:__|trans|@lang|Lang::get)\(\s*([\'"])((?:(?!\1).)+)\1/', $source, $matches);

        return array_values(array_unique($matches[2]));
    }
}
