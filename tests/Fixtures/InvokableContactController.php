<?php

namespace Gadya\Cms\Tests\Fixtures;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A single-action controller, registered as `Route::post('/x',
 * InvokableContactController::class)`: Laravel keeps its action as the
 * class name alone.
 */
class InvokableContactController
{
    public function __invoke(Request $request): RedirectResponse
    {
        return back()->with('status', __('pages.contact.success'));
    }
}
