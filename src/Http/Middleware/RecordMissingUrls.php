<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Models\BrokenLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes the addresses visitors ask for and do not get.
 *
 * Every one of them is either a link somebody else has published, an old
 * address the site forgot to forward, or a typo in print - and the first
 * two are worth a redirect.
 */
class RecordMissingUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            rescue(fn () => BrokenLink::note(
                url: Str::limit(Str::start($request->path(), '/'), 500, ''),
                kind: BrokenLink::VISITED,
                referrer: parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null,
                status: 404,
            ), report: false);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 404 || ! $request->isMethod('GET') || ! config('gadya-cms.broken_links.record', true)) {
            return false;
        }

        if ($request->expectsJson() || VisitorFingerprint::isBot($request)) {
            return false;
        }

        $path = trim($request->path(), '/');

        foreach ((array) config('gadya-cms.analytics.skip_prefixes', []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        /*
         * A request for a missing file is somebody else's problem - an old
         * favicon, a plugin scan - and would bury the addresses that matter.
         */
        return ! Str::contains($path, '.') && $path !== '';
    }
}
