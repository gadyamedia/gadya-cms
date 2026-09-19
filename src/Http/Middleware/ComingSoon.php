<?php

namespace Gadya\Cms\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Gadya\Cms\Filament\GadyaCmsPlugin;
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
            ...GadyaCmsPlugin::brandTokens(),
            'logo' => rescue(fn (): ?string => GadyaCmsPlugin::brandLogo(), null, report: false),
            'site' => (string) config('gadya-cms.brand.name', config('app.name')),
            'heading' => $this->maintenance->heading(),
            'message' => $this->maintenance->message(),
            'until' => $this->maintenance->until(),
            'asks' => $this->maintenance->hasPassword(),
        ], 503)->header('Retry-After', '3600');
    }

    private function isExempt(Request $request): bool
    {
        $path = trim($request->path(), '/');

        /*
         * Livewire 4 serves its updates from a hashed prefix
         * (`livewire-eab0293a/update`), so it is matched on its start rather
         * than as a whole segment - otherwise every click in the panel is
         * answered with the notice.
         */
        if (str_starts_with($path, 'livewire')) {
            return true;
        }

        $prefixes = array_merge(
            [$this->panelPath(), (string) config('gadya-cms.editor.prefix', 'cms'), 'storage', 'up', 'build', 'vendor', 'filament', 'css', 'js', 'fonts', 'gadya-connect'],
            (array) config('gadya-cms.maintenance.allow_prefixes', []),
        );

        foreach (array_filter($prefixes) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private function panelPath(): string
    {
        return trim((string) rescue(
            fn (): string => Filament::getPanel((string) config('gadya-cms.panel', 'admin'))->getPath(),
            'admin',
            report: false,
        ), '/');
    }
}
