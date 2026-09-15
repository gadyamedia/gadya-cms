<?php

namespace Gadya\Cms\Contracts;

/**
 * Where a page lives on the public site.
 *
 * The package knows a page by its slug; only the application knows which
 * route serves it. Most sites answer "/" for the home page and "/slug" for
 * everything else, which is what the default implementation does. A site
 * with nested addresses - a location under /places, say - binds its own.
 */
interface ResolvesPagePaths
{
    /**
     * The public path for a page, starting with a slash.
     *
     * @param  array<string, mixed>  $document
     */
    public function publicPathFor(string $slug, array $document): string;
}
