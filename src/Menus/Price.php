<?php

namespace Gadya\Cms\Menus;

/**
 * Money on a menu, kept as whole cents from the moment it is typed.
 *
 * A float never touches a price: "4.50" typed in the panel becomes 450,
 * and 450 is what an ordering service would be handed. Only the page
 * turns it back into "$4.50".
 */
class Price
{
    /** "4.50", "$4.50", "4" or 4.5 to cents; blank is null (no price shown). */
    public static function toCents(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        $clean = preg_replace('/[^0-9.]/', '', (string) $value) ?? '';

        if ($clean === '' || ! preg_match('/^\d*(\.\d{0,2})?$/', $clean)) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $clean, 2), 2, '');

        return ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    /** Cents as the form shows them: 450 is "4.50". */
    public static function toDecimal(?int $cents): ?string
    {
        return $cents === null ? null : intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Cents as a menu shows them: "$4.50", or "$12" for a whole amount. */
    public static function format(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        $symbol = (string) config('gadya-cms.menus.currency_symbol', '$');

        return $symbol.($cents % 100 === 0 ? (string) intdiv($cents, 100) : (string) static::toDecimal($cents));
    }

    public static function currency(): string
    {
        return (string) config('gadya-cms.menus.currency', 'USD');
    }
}
