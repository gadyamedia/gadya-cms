<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Points agents at the sitemap and llms.txt from the home page's own
 * headers (RFC 8288), so a crawler that reads only the first response
 * still learns where the machine-readable versions live.
 */
class AdvertiseDiscovery
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->applies($request, $response)) {
            return $response;
        }

        $links = [];

        if (config('gadya-cms.seo.llms', true)) {
            $links[] = '<'.url('/llms.txt').'>; rel="describedby"; type="text/markdown"';
        }

        if (config('gadya-cms.seo.sitemap', true)) {
            $links[] = '<'.url('/sitemap.xml').'>; rel="sitemap"; type="application/xml"';
        }

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
