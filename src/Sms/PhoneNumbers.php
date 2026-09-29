<?php

namespace Gadya\Cms\Sms;

/**
 * A mobile number as a person types it - "(732) 555-0100", "732.555.0100",
 * "+44 7700 900123" - in the one shape Twilio takes: E.164. A ten-digit
 * number is taken to be American, because nearly every one typed here is.
 */
final class PhoneNumbers
{
    public static function normalise(mixed $number): ?string
    {
        if (! is_string($number) && ! is_int($number)) {
            return null;
        }

        $number = trim((string) $number);
        $international = str_starts_with($number, '+');
        $digits = (string) preg_replace('/\D+/', '', $number);

        if (! $international && strlen($digits) === 10 && $digits[0] >= '2') {
            return '+1'.$digits;
        }

        if (! $international && strlen($digits) === 11 && str_starts_with($digits, '1') && $digits[1] >= '2') {
            return '+'.$digits;
        }

        if ($international && strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0') {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $numbers
     * @return list<string>
     */
    public static function normaliseAll(array $numbers): array
    {
        return array_values(array_unique(array_filter(array_map(self::normalise(...), $numbers))));
    }

    /** "+17325550100" as "(732) 555-0100", for reading. */
    public static function display(string $number): string
    {
        return preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $number, $match) === 1
            ? "({$match[1]}) {$match[2]}-{$match[3]}"
            : $number;
    }
}
