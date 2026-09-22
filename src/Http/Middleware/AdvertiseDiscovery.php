<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Seo\AgentDiscovery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points agents at the sitemap, llms.txt and the API catalogue from the
 * home page's own headers (RFC 8288, RFC 9727 section 3), so a crawler
 * that reads only the first response still learns where the
 * machine-readable versions live.
 */
class AdvertiseDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->applies($request, $response)) {
            return $response;
        }

        $links = app(AgentDiscovery::class)->links();

        if ($links !== []) {
            $response->headers->set('Link', implode(', ', $links), false);
        }

        return $response;
    }

    private function applies(Request $request, Response $response): bool
    {
        return config('gadya-cms.seo.link_headers', true)
            && $request->isMethodCacheable()
            && trim($request->path(), '/') === ''
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
