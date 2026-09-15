<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Editor\EditingLock;
use Gadya\Cms\Services\PublishSiteContent;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PublishController extends Controller
{
    public function __invoke(Request $request, PublishSiteContent $action, EditingLock $lock): RedirectResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();

        if ($lock->holder() !== null && ! $lock->isHeldBy($user)) {
            return back()->withErrors([
                'publish' => "{$lock->holder()} is editing right now. Wait until they finish before publishing.",
            ]);
        }

        $action->handle($user);

        return back()->with('status', 'Your changes are now live.');
    }
}
