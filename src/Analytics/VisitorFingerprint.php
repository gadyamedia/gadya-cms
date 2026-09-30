<?php

namespace Gadya\Cms\Analytics;

use Gadya\Cms\Privacy\Consent;
use Gadya\Cms\Support\ClientIp;
use Gadya\Cms\Support\SiteTimezone;
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
        /*
         * Someone who refused analytics in the privacy banner is still
         * counted when she taps the phone number or sends a form, but
         * as nobody in particular: a fresh random value every time, which
         * cannot be matched to anything else she does.
         */
        if (app(Consent::class)->refuses(Consent::ANALYTICS, $request)) {
            return bin2hex(random_bytes(32));
        }

        return hash_hmac(
            'sha256',
            (ClientIp::for($request) ?? '').'|'.$request->userAgent().'|'.app(SiteTimezone::class)->now()->toDateString(),
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
        return BotDetector::isBot($request);
    }
}
