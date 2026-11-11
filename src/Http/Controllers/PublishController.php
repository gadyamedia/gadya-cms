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

        $holder = $lock->isHeldBy($user) ? null : $lock->holder();

        /*
         * Someone else is editing: publishing still goes ahead when asked to on purpose ("Publish anyway"),
         * since the draft is one for everyone - it is how a colleague puts her changes live for her.
         */
        if ($holder !== null && ! $request->boolean('anyway')) {
            return back()->withErrors([
                'publish' => "{$holder} is editing right now. Wait until they finish, or publish anyway to put everyone's changes live.",
            ]);
        }

        $action->handle($user);

        return back()->with('status', $holder === null ? 'Your changes are now live.' : "Everyone's changes are now live, {$holder}'s included.");
    }
}
