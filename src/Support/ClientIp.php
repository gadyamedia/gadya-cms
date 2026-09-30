<?php

namespace Gadya\Cms\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The visitor's address for a request, in one place.
 *
 * Behind Cloudflare `$request->ip()` is an edge server, shared by everyone
 * who reaches that location. `CF-Connecting-IP` carries the real address,
 * and is believed only when the request's direct peer is itself a
 * Cloudflare address: anyone reaching the origin directly has a peer that
 * is not, so a forged header is ignored. Anywhere else `$request->ip()` is
 * already right (trusted proxies configured, or no proxy at all).
 */
class ClientIp
{
    /**
     * Cloudflare's published ranges, as of 2026-09-30, from
     * https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6.
     *
     * @var list<string>
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function for(Request $request): ?string
    {
        if (self::honoursCloudflare() && self::cameFromCloudflare($request)) {
            $header = trim((string) $request->headers->get('CF-Connecting-IP'));

            if ($header !== '' && filter_var($header, FILTER_VALIDATE_IP) !== false) {
                return $header;
            }
        }

        return $request->ip();
    }

    public static function honoursCloudflare(): bool
    {
        return (bool) config('gadya-cms.client_ip.trust_cloudflare', true);
    }

    public static function cameFromCloudflare(Request $request): bool
    {
        $peer = $request->server('REMOTE_ADDR');

        return is_string($peer) && $peer !== '' && IpUtils::checkIp($peer, self::trustedRanges());
    }

    /**
     * @return list<string>
     */
    public static function trustedRanges(): array
    {
        $extra = array_values(array_filter((array) config('gadya-cms.client_ip.extra_trusted_ranges', []), 'is_string'));

        return [...self::CLOUDFLARE_RANGES, ...$extra];
    }
}
