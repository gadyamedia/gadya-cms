<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Analytics\VisitorFingerprint;
use Gadya\Cms\Models\AnalyticsEvent;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * The beacon the public site posts to when a visitor does something worth
 * counting - tapping the phone number, opening a booking form.
 *
 * The event name is checked against a fixed list, so a page cannot invent
 * new metrics and nothing arbitrary reaches the database.
 */
class AnalyticsEventController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', Rule::in((array) config('gadya-cms.analytics.events', []))],
            'path' => ['required', 'string', 'starts_with:/', 'max:255'],
            'metadata' => ['nullable', 'array', 'max:5'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ]);

        if (! VisitorFingerprint::isBot($request)) {
            rescue(fn () => AnalyticsEvent::create([
                'site_id' => app(SiteContext::class)->id(),
                'name' => $validated['name'],
                'path' => $validated['path'],
                'visitor_hash' => VisitorFingerprint::hash($request),
                'metadata' => $validated['metadata'] ?? null,
                'created_at' => now(),
            ]), report: false);
        }

        return response()->json(['recorded' => true]);
    }
}
