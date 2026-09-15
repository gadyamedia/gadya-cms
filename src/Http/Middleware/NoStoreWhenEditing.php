<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Editor\EditContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page rendered while `cms.editing` is active in session contains DRAFT
 * content, not the published site. Without an explicit no-store directive,
 * a shared cache sitting in front of the app (Cloudflare, nginx
 * fastcgi_cache, a corporate proxy) could store that draft response and
 * later serve it to an anonymous guest.
 */
class NoStoreWhenEditing
{
    public function __construct(private readonly EditContext $editContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->editContext->showsDraft()) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        return $response;
    }
}
