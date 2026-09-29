<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Forms\Attribution;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes the first page of a visit in the session - its campaign, the site
 * that sent the visitor, the address they landed on - so an enquiry sent
 * three pages later still says where it began, even without the forms
 * script. One small write per session, on its first page; nothing on any
 * other request, and nothing sent anywhere.
 */
class RememberFirstTouch
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (
            config('gadya-cms.forms.builder.attribution.enabled', true)
            && $request->isMethod('GET')
            && $request->hasSession()
            && ! $request->expectsJson()
            && $response->isSuccessful()
            && ! VisitorFingerprint::isBot($request)
            && ! $this->skipped($request)
        ) {
            rescue(fn () => app(Attribution::class)->remember($request), report: false);
        }

        return $response;
    }

    private function skipped(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach ((array) config('gadya-cms.analytics.skip_prefixes', []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
