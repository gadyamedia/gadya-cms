<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Analytics\VisitorGeo;
use Gadya\Cms\Events\PageViewed;
use Gadya\Cms\Models\PageView;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records a page view after the response is built.
 *
 * Every write is rescued: analytics are worth having, but never at the cost
 * of a visitor's page. If the table is missing or the database is briefly
 * unhappy, the visitor still gets her page and the count is simply lost.
 */
class TrackPageViews
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldTrack($request, $response)) {
            return $response;
        }

        rescue(function () use ($request): void {
            $geo = VisitorGeo::for($request);

            /*
             * Campaign parameters are remembered for the session, so a lead
             * that arrives three pages later is still credited to the
             * campaign that brought her in.
             */
            foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $parameter) {
                if ($request->query($parameter)) {
                    $request->session()->put(
                        'gadya-cms.attribution.'.$parameter,
                        Str::limit((string) $request->query($parameter), 120, ''),
                    );
                }
            }

            $view = PageView::create([
                'site_id' => app(SiteContext::class)->id(),
                'path' => Str::limit(Str::start($request->path(), '/'), 255, ''),
                'route_name' => $request->route()?->getName(),
                'visitor_hash' => VisitorFingerprint::hash($request),
                'referrer_host' => parse_url((string) $request->headers->get('referer'), PHP_URL_HOST) ?: null,
                'utm_source' => $request->session()->get('gadya-cms.attribution.utm_source'),
                'utm_medium' => $request->session()->get('gadya-cms.attribution.utm_medium'),
                'utm_campaign' => $request->session()->get('gadya-cms.attribution.utm_campaign'),
                'device_category' => VisitorFingerprint::deviceCategory($request),
                'country' => $geo['country'],
                'region' => $geo['region'],
                'city' => $geo['city'],
                'viewed_at' => now(),
            ]);

            /*
             * The dashboard's live panel. Rescued separately so a socket
             * that is down never costs a visitor her page, and never loses
             * the view that was already recorded above.
             */
            rescue(fn () => PageViewed::dispatch(
                $view->path,
                $view->country,
                $view->city,
                (string) $view->device_category,
            ), report: false);
        }, report: false);

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! config('gadya-cms.analytics.enabled', true)) {
            return false;
        }

        if (! $request->isMethod('GET') || ! $response->isSuccessful() || ! $request->hasSession()) {
            return false;
        }

        if ($request->expectsJson() || VisitorFingerprint::isBot($request)) {
            return false;
        }

        /*
         * An editor walking her own site would otherwise drown the numbers
         * that tell her how visitors behave.
         */
        if ($request->user()?->can((string) config('gadya-cms.gate', 'manage-content'))) {
            return false;
        }

        $path = trim($request->path(), '/');

        foreach ((array) config('gadya-cms.analytics.skip_prefixes', []) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return false;
            }
        }

        return ! in_array($path, (array) config('gadya-cms.analytics.skip_paths', []), true);
    }
}
