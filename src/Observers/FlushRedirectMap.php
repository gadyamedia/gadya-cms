<?php

namespace Gadya\Cms\Observers;

use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Redirects\RedirectMap;

class FlushRedirectMap
{
    public function saved(Redirect $redirect): void
    {
        app(RedirectMap::class)->flush();
    }

    public function deleted(Redirect $redirect): void
    {
        app(RedirectMap::class)->flush();
    }
}
