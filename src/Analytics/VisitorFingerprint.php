<?php

namespace Gadya\Cms\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who a visitor is, without ever knowing who a visitor is.
 *
 * No cookie is set and no IP address is stored - only an HMAC derived from
 * one, with today's date mixed in, so the same person gets a fresh
 * identifier every day. Counting people once a day still works; following
 * one person across days is impossible by construction rather than by
 * policy, which is the whole point of not using Google for this.
 */
class VisitorFingerprint
{
    public static function hash(Request $request): string
    {
        return hash_hmac(
            'sha256',
            $request->ip().'|'.$request->userAgent().'|'.now()->toDateString(),
            (string) config('app.key'),
        );
    }

    public static function deviceCategory(Request $request): string
    {
        $agent = Str::lower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'android') || str_contains($agent, 'iphone') => 'mobile',
            default => 'desktop',
        };
    }

    public static function isBot(Request $request): bool
    {
        return (bool) preg_match(
            '/bot|crawl|spider|slurp|lighthouse|headless|preview|fetch|scan|monitor|curl|wget/i',
            (string) $request->userAgent(),
        );
    }
}
