<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Localisation\Locales;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers /es/about as the Spanish /about.
 *
 * The language prefix is taken off the path before the router sees it and
 * becomes part of the request's base URL instead, the way an application
 * installed in a subdirectory is served. The application's own routes -
 * which the package does not own and cannot prefix - then match unchanged,
 * and every url(), route() and redirect built while answering carries the
 * prefix by itself, so a Spanish visitor stays on the Spanish site however
 * the template builds its links. Assets are still served from the root.
 *
 * Global and first, so redirects, the coming-soon notice and everything
 * else see the path the application knows. With one language enabled it
 * does nothing at all.
 */
class LocaliseRequest
{
    public const ATTRIBUTE = 'gadya-cms.locale-prefix';

    public function __construct(private readonly Locales $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->locales->isMultilingual()) {
            return $next($request);
        }

        $path = $request->getPathInfo();
        $segment = explode('/', ltrim($path, '/'))[0];

        /*
         * The default language has no prefix of its own; /en/about is sent
         * to /about so there is only ever one address for a page.
         */
        if ($segment === $this->locales->default() && $request->isMethodSafe()) {
            $rest = substr($path, strlen($segment) + 1) ?: '/';
            $query = $request->getQueryString();

            return redirect()->to($request->getSchemeAndHttpHost().$request->getBaseUrl().'/'.ltrim($rest, '/').($query !== null ? '?'.$query : ''), 301);
        }

        if ($segment === '' || ! in_array($segment, $this->locales->additional(), true)) {
            return $next($request);
        }

        $localised = $this->underPrefix($request, $segment);
        $localised->attributes->set(self::ATTRIBUTE, '/'.$segment);

        $previousLocale = App::getLocale();
        $this->locales->use($segment);
        App::setLocale($segment);

        /** @var UrlGenerator $url */
        $url = app('url');
        $holdsAssetRoot = blank(config('app.asset_url'));

        if ($holdsAssetRoot) {
            $url->useAssetOrigin($request->getSchemeAndHttpHost().$request->getBaseUrl());
        }

        try {
            return $next($localised);
        } finally {
            if ($holdsAssetRoot) {
                $url->useAssetOrigin(null);
            }

            /*
             * Put things back as they were for whatever runs next in this
             * process - a long-lived worker, or the next request of a test.
             */
            App::setLocale($previousLocale);
            $this->locales->use(null);
            app()->instance('request', $request);
        }
    }

    /**
     * The same request, with the prefix moved from the path into the base.
     */
    private function underPrefix(Request $request, string $prefix): Request
    {
        $server = $request->server->all();

        $file = (string) ($server['SCRIPT_FILENAME'] ?? '') ?: public_path('index.php');
        $directory = rtrim(str_replace('\\', '/', dirname((string) (($server['SCRIPT_NAME'] ?? '') ?: '/index.php'))), '/');
        $script = $directory.'/'.$prefix.'/'.basename($file);

        $uri = (string) ($server['REQUEST_URI'] ?? $request->getRequestUri());
        [$uriPath, $query] = array_pad(explode('?', $uri, 2), 2, null);

        /* "/es" alone is the Spanish home page, which needs its trailing slash to be seen as one. */
        if (rtrim($uriPath, '/') === $directory.'/'.$prefix) {
            $uri = $directory.'/'.$prefix.'/'.($query !== null ? '?'.$query : '');
        }

        return $request->duplicate(server: [
            ...$server,
            'SCRIPT_FILENAME' => $file,
            'SCRIPT_NAME' => $script,
            'PHP_SELF' => $script,
            'REQUEST_URI' => $uri,
        ]);
    }
}
