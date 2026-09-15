<?php

namespace Gadya\Cms\Editor;

use Illuminate\Support\Facades\URL;

class PreviewLink
{
    /**
     * A signed link to the draft version of a path, good for as long as
     * the configuration allows.
     */
    public function for(string $path): string
    {
        return URL::temporarySignedRoute(
            'gadya-cms.preview',
            now()->addHours(max(1, (int) config('gadya-cms.preview.expires_hours', 72))),
            ['path' => '/'.ltrim($path, '/')],
        );
    }
}
