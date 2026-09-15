<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Editor\EditContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A link the client can send to someone who has no account, to look at the
 * draft before it goes live. The link is signed and expires; opening it
 * marks the browser as previewing for the same period, so following links
 * around the draft site keeps working.
 */
class PreviewController extends Controller
{
    public function show(Request $request): RedirectResponse
    {
        $path = '/'.ltrim((string) $request->query('path', '/'), '/');

        /*
         * Only a path on this site: the signature covers it, but the
         * cheapest guard against an open redirect is to never build one.
         */
        if (str_contains($path, '//') || str_contains($path, '\\')) {
            $path = '/';
        }

        $request->session()->put(EditContext::PREVIEW_SESSION_KEY, (int) $request->query('expires'));

        return redirect()->to($path);
    }

    public function stop(Request $request): RedirectResponse
    {
        $request->session()->forget(EditContext::PREVIEW_SESSION_KEY);

        return redirect()->to('/'.ltrim((string) $request->input('path', '/'), '/'));
    }
}
