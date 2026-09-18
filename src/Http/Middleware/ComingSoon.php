<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Gadya\Cms\Support\Maintenance;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the public site closed while it is being built, without closing
 * the panel, the editor or the assets they need.
 */
class ComingSoon
{
    public function __construct(private readonly Maintenance $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->maintenance->isOn() || $this->isExempt($request)) {
            return $next($request);
        }

        if ($this->maintenance->allows($request)) {
            $response = $next($request);

            /*
             * Remember the password so the rest of the visit works without
             * it hanging off every address.
             */
            if ($request->filled('pass')) {
                $response->headers->setCookie(new Cookie(Maintenance::COOKIE, (string) $request->input('pass'), now()->addDays(30)->getTimestamp(), '/', null, $request->secure(), true, false, Cookie::SAMESITE_LAX));
            }

            return $response;
        }

        return response()->view('gadya-cms::maintenance', [
            'heading' => $this->maintenance->heading(),
            'message' => $this->maintenance->message(),
            'until' => $this->maintenance->until(),
            'asks' => $this->maintenance->hasPassword(),
        ], 503)->header('Retry-After', '3600');
    }

    private function isExempt(Request $request): bool
    {
        $path = trim($request->path(), '/');

        foreach (array_merge(['admin', (string) config('gadya-cms.editor.prefix', 'cms'), 'livewire', 'storage', 'up', 'build', 'vendor'], (array) config('gadya-cms.maintenance.allow_prefixes', [])) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
