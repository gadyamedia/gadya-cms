<?php

namespace Gadya\Cms\Forms;

use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Models\Form;
use Gadya\Cms\Models\Translation;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * The language a visitor sees a built form in.
 *
 * Usually that is the CMS's own: `/es/contact` is Spanish. But many sites
 * choose the language themselves - from the session, a cookie, or a
 * `/{locale}` prefix of their own routes - and only tell Laravel, with
 * `App::setLocale()`. A form on such a site has to follow the app, not
 * the CMS, or a Russian page would carry an English form. So whatever the
 * app says is the language, unless the CMS is answering the request in one
 * of its own.
 *
 * The form's post goes to the package's own address, which the app's
 * locale middleware may never see, so the form carries the language it
 * was drawn in (`_locale`) and the answer - the errors, the thank-you -
 * comes back in it.
 */
class FormLocale
{
    public const INPUT = '_locale';

    public function __construct(private readonly Locales $locales) {}

    public function current(): string
    {
        if ($this->locales->isTranslating()) {
            return $this->locales->current();
        }

        return self::normalise(App::getLocale()) ?? $this->locales->default();
    }

    /** Whether the form needs putting into another language than the one it was written in. */
    public function isTranslating(): bool
    {
        return $this->current() !== $this->locales->default();
    }

    /**
     * Take the language a form was drawn in from its post, when the app
     * has not already said which language this request is in. Only a
     * language the site can actually speak is taken: an enabled CMS
     * language, one the app has lang files for, or one the form has been
     * translated into.
     */
    public function adopt(Request $request, ?Form $form = null): void
    {
        $posted = self::normalise($request->input(self::INPUT));

        if ($posted === null || $this->locales->isTranslating() || $posted === self::normalise(App::getLocale())) {
            return;
        }

        if (! $this->speaks($posted, $form)) {
            return;
        }

        App::setLocale($posted);
    }

    /**
     * The same, for a language only this form speaks - one it has been
     * translated into, with no lang files of the app's - and the form put
     * into it.
     */
    public function adoptFor(Request $request, Form $form): Form
    {
        $before = $this->current();

        $this->adopt($request, $form);

        return $this->current() === $before ? $form : $form->inVisitorLanguage();
    }

    public function speaks(string $locale, ?Form $form = null): bool
    {
        if ($this->locales->isEnabled($locale) || $locale === $this->locales->default()) {
            return true;
        }

        $underscored = str_replace('-', '_', $locale);

        foreach ([$locale, $underscored] as $candidate) {
            if (is_dir(lang_path($candidate)) || is_file(lang_path($candidate.'.json'))) {
                return true;
            }
        }

        if ($form !== null && in_array($locale, array_column((array) $form->setting('localised', []), 'locale'), true)) {
            return true;
        }

        return $form !== null && rescue(fn (): bool => Translation::query()
            ->where('site_id', app(SiteContext::class)->id())
            ->where('locale', $locale)
            ->where('key', 'form:'.$form->getKey())
            ->exists(), false, report: false);
    }

    /** `ru`, `pt-br`: lower case, a hyphen; null for anything that is not a language code. */
    public static function normalise(mixed $locale): ?string
    {
        if (! is_string($locale)) {
            return null;
        }

        $locale = strtolower(str_replace('_', '-', trim($locale)));

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $locale) === 1 ? $locale : null;
    }
}
