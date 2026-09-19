<?php

namespace Gadya\Cms\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Coarse visitor geography, taken from the headers the CDN already puts on
 * the request.
 *
 * Nothing is looked up and no address is sent anywhere: Cloudflare sets
 * CF-IPCountry on every proxied request, and region and city when the
 * visitor-location transform is switched on. Off the CDN - local, tests -
 * everything is simply null, and the panel says so rather than pretending.
 */
class VisitorGeo
{
    /**
     * @return array{country: ?string, region: ?string, city: ?string}
     */
    public static function for(Request $request): array
    {
        $country = Str::upper((string) $request->headers->get('CF-IPCountry'));

        // Cloudflare sends XX for unknown and T1 for Tor exits.
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || in_array($country, ['XX', 'T1'], true)) {
            $country = null;
        }

        return [
            'country' => $country,
            'region' => static::label($request, 'cf-region'),
            'city' => static::label($request, 'cf-ipcity'),
        ];
    }

    /** For display: 🇺🇸 from "US". */
    public static function flag(?string $country): string
    {
        if ($country === null || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return '🌐';
        }

        return mb_chr(0x1F1E6 + ord($country[0]) - ord('A'))
            .mb_chr(0x1F1E6 + ord($country[1]) - ord('A'));
    }

    /** For display: "United States" from "US", or the code itself without the intl extension. */
    public static function countryName(string $country): string
    {
        $country = strtoupper($country);

        if (! class_exists(\Locale::class)) {
            return $country;
        }

        $name = \Locale::getDisplayRegion('-'.$country, 'en');

        return $name === '' || $name === $country ? $country : $name;
    }

    private static function label(Request $request, string $header): ?string
    {
        $value = trim((string) $request->headers->get($header));

        return $value === '' ? null : Str::limit($value, 100, '');
    }
}
