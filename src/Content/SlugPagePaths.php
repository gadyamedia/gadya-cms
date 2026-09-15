<?php

namespace Gadya\Cms\Content;

use Gadya\Cms\Contracts\ResolvesPagePaths;

/**
 * The plain answer: the home page at the root, every other page at its
 * own slug.
 */
class SlugPagePaths implements ResolvesPagePaths
{
    public function publicPathFor(string $slug, array $document): string
    {
        return $slug === (string) config('gadya-cms.pages.home_slug', 'home') ? '/' : '/'.$slug;
    }
}
