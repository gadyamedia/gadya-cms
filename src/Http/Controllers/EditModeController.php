<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Editor\EditContext;
use Gadya\Cms\Editor\EditingLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class EditModeController extends Controller
{
    public function enable(Request $request, EditingLock $lock, PageRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'string', Rule::in([...array_keys($registry->editablePages()), 'home'])],
        ]);

        $request->session()->put(EditContext::SESSION_KEY, true);

        /** @var Authenticatable $user */
        $user = $request->user();
        $lock->acquire($user);

        return redirect()->to($registry->publicUrlFor($data['page'] ?? 'home', $registry->draftDocument()));
    }

    public function disable(Request $request, EditingLock $lock): RedirectResponse
    {
        $request->session()->forget(EditContext::SESSION_KEY);

        /** @var Authenticatable $user */
        $user = $request->user();
        $lock->release($user);

        return back();
    }
}
