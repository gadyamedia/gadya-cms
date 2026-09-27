<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Localisation\Locales;
use Gadya\Cms\Localisation\TranslateContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * "Translate this page" and "Mark as reviewed" on the live editor's
 * toolbar, for the language the page is being edited in.
 */
class TranslationController extends Controller
{
    public function translate(Request $request, TranslateContent $content, Locales $locales): RedirectResponse
    {
        $key = $this->validatedKey($request, $content, $locales);

        $content->queue([$key], $locales->current(), $request->user());

        return back()->with('status', 'Translating into '.$locales->englishName($locales->current()).'. It arrives as a draft for you to read through.');
    }

    public function review(Request $request, TranslateContent $content, Locales $locales): RedirectResponse
    {
        $key = $this->validatedKey($request, $content, $locales);

        $content->approve($key, $locales->current(), $request->user());

        return back()->with('status', 'Marked as reviewed. It goes live when you publish.');
    }

    private function validatedKey(Request $request, TranslateContent $content, Locales $locales): string
    {
        abort_unless($locales->isTranslating(), 404);

        return (string) $request->validate([
            'key' => ['required', 'string', Rule::in(array_keys($content->units()))],
        ])['key'];
    }
}
