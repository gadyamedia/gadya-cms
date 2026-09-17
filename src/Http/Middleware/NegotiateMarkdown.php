<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Content\PageRegistry;
use Gadya\Cms\Content\PublicDocument;
use Gadya\Cms\Content\SiteContentRepository;
use Gadya\Cms\Seo\Markdown;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page asked for as `text/markdown` is answered from the document
 * rather than the template, for whichever page lives at that path.
 * Articles negotiate in their own controller; this covers the pages,
 * whose templates belong to the application.
 */
class NegotiateMarkdown
{
    public function __construct(
        private readonly SiteContentRepository $repository,
        private readonly PublicDocument $public,
        private readonly PageRegistry $registry,
        private readonly Markdown $markdown,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! Markdown::wanted($request)) {
            return $next($request);
        }

        $document = $this->public->from($this->repository->published());

        foreach ($document['pages'] ?? [] as $slug => $page) {
            if (! is_array($page) || $this->registry->isHidden($page)) {
                continue;
            }

            if ($this->registry->publicPathFor((string) $slug, $document) === '/'.trim($request->path(), '/') || ($request->path() === '/' && $this->registry->publicPathFor((string) $slug, $document) === '/')) {
                return response($this->markdown->forPage($page, $request->url()))
                    ->header('Content-Type', 'text/markdown; charset=utf-8')
                    ->header('Vary', 'Accept');
            }
        }

        return $next($request);
    }
}
