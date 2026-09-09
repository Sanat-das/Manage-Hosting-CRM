<?php

namespace App\Support;

/**
 * Render a configurable option's numeric bound the way a person writes it.
 *
 * The min / max / step columns are `decimal:2` because a step may genuinely be
 * fractional (0.5-core increments, 1.5 TB). But the cast stringifies EVERY
 * value to two places, so a whole number came out as "1.00" — a slider labelled
 * "1.00 vCPU", a Min box reading "8.00", an admin typing 32 and being shown
 * "32.00" back. Storage precision is right; the rendering was not.
 *
 * This trims the decimal tail only when it carries no information: 32.00 → 32,
 * 0.50 → 0.5, 1.25 → 1.25. It is display-only — nothing here changes what is
 * stored, validated or charged, and OptionPricingResolver::normaliseAmount does
 * the same job for a value already chosen.
 *
 * Money is deliberately NOT run through this: a price reads "199.00".
 */
class OptionNumber
{
    /**
     * @param  mixed  $value  a decimal string, int, float or null
     * @param  string  $default  what to render when there is no value at all
     */
    public static function format(mixed $value, string $default = ''): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        $number = (float) $value;

        // Whole numbers lose the tail entirely; fractional ones keep only the
        // digits they need. rtrim on the '.' guards "5." from a value like 5.0.
        return $number == (int) $number
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }
}
