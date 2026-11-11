<?php

namespace Gadya\Cms\Support;

/**
 * The address behind a link the client typed in the live editor: a waiver, a social page, a page on the
 * site. Only web addresses, site paths, anchors, email and phone links get through, so a pasted
 * `javascript:` address never runs in a visitor's browser. A bare domain, which is what people paste
 * most, is given https://.
 */
class SafeLink
{
    public static function href(mixed $address): ?string
    {
        if (! is_string($address)) {
            return null;
        }

        $address = trim($address);

        if ($address === '') {
            return null;
        }

        if (preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $address) === 1) {
            return $address;
        }

        if (preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+(/\S*)?$~i', $address) === 1) {
            return 'https://'.$address;
        }

        return null;
    }

    /**
     * Whether a link leaves this site, and so opens in a new tab.
     */
    public static function isExternal(?string $href): bool
    {
        if ($href === null || preg_match('~^https?://~i', $href) !== 1) {
            return false;
        }

        return strcasecmp((string) parse_url($href, PHP_URL_HOST), request()->getHost()) !== 0;
    }
}
