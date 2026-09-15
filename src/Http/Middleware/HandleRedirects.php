<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Models\Redirect;
use Gadya\Cms\Redirects\RedirectMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a visitor from an old address to the new one before the router
 * has a chance to say "not found". Only plain page requests are
 * considered: a form post to an old address is a bug to fix, not a thing
 * to quietly forward.
 */
class HandleRedirects
{
    public function __construct(private readonly RedirectMap $map) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $match = $this->map->match($request->path());

        if ($match === null) {
            return $next($request);
        }

        rescue(fn () => Redirect::query()->whereKey($match['id'])->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]), report: false);

        $to = $match['to'];

        if ($request->getQueryString() !== null && ! str_contains($to, '?')) {
            $to .= '?'.$request->getQueryString();
        }

        return redirect()->to($to, $match['status']);
    }
}
