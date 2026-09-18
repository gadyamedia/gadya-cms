<?php

namespace Gadya\Cms\Http\Controllers;

use Gadya\Cms\Models\Subscriber;
use Gadya\Cms\Support\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * The email box in the footer, and the way back out of it.
 */
class NewsletterController extends Controller
{
    public function store(Request $request, SiteContext $siteContext): JsonResponse|RedirectResponse
    {
        $honeypot = (string) config('gadya-cms.forms.honeypot', 'website');

        /*
         * Answer a bot as though it worked, so it learns nothing, and
         * answer someone already on the list the same way, so the form
         * never reveals who is subscribed.
         */
        if ($honeypot !== '' && filled($request->input($honeypot))) {
            return $this->done($request);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $subscriber = Subscriber::query()->firstOrNew([
            'site_id' => $siteContext->id(),
            'email' => Str::lower($data['email']),
        ]);

        $subscriber->fill([
            'name' => $data['name'] ?? $subscriber->name,
            'status' => Subscriber::SUBSCRIBED,
            'unsubscribed_at' => null,
            'source' => $subscriber->source ?? Str::limit((string) ($request->input('_path') ?: $request->headers->get('referer')), 255, ''),
        ])->save();

        return $this->done($request);
    }

    public function unsubscribe(Request $request, Subscriber $subscriber): JsonResponse|RedirectResponse
    {
        $subscriber->unsubscribe();

        $message = (string) config('gadya-cms.newsletter.unsubscribed', 'You have been taken off the list.');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->to('/')->with('gadya-cms.newsletter', $message);
    }

    private function done(Request $request): JsonResponse|RedirectResponse
    {
        $message = (string) config('gadya-cms.newsletter.success', 'Thank you. We will be in touch.');

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return back()->with('gadya-cms.newsletter', $message);
    }
}
