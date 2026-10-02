<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Spell a rupee amount in words, the way a written cheque does, for the
 * invoice PDF.
 *
 * Why this exists: the PDF needs "Rupees Nine Crore Ninety Nine Lakh Ninety
 * Nine Thousand Nine Hundred Ninety Nine and Ninety Nine Paise Only", which
 * neither number_format() nor the Western thousand/million grouping can
 * produce. Indian numbering is used throughout: 1,00,000 is one lakh,
 * 1,00,00,000 is one crore.
 *
 * Paise come from the fractional part scaled to two digits and rounded, so a
 * float like 5.10 spells "Ten Paise" and not the "Nine Paise" that a naive
 * floor of 5.1 * 100 would produce.
 *
 * Money at hundredths of a paisa does not exist here; invoices are never
 * negative, so a negative amount is simply spelled as its absolute value.
 */
final class AmountInWords
{
    private const ONES = [
        'Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function convert(float $amount): string
    {
        $amount = abs($amount);

        $whole = (int) floor($amount);
        $paise = (int) round(($amount - floor($amount)) * 100);

        $words = 'Rupees '.self::numberToWords($whole, $paise === 0 && $whole % 1000 >= 100 && $whole % 100 > 0);

        if ($paise > 0) {
            $words .= ' and '.self::numberToWords($paise).' Paise';
        }

        return $words.' Only';
    }

    private static function numberToWords(int $number, bool $withAnd = false): string
    {
        if ($number < 1000) {
            return self::belowThousand($number, $withAnd);
        }

        if ($number < 100000) {
            return self::numberToWords(intdiv($number, 1000)).' Thousand'.self::tail($number % 1000, $withAnd);
        }

        if ($number < 10000000) {
            return self::numberToWords(intdiv($number, 100000)).' Lakh'.self::tail($number % 100000, $withAnd);
        }

        return self::numberToWords(intdiv($number, 10000000)).' Crore'.self::tail($number % 10000000, $withAnd);
    }

    private static function tail(int $number, bool $withAnd = false): string
    {
        return $number > 0 ? ' '.self::numberToWords($number, $withAnd) : '';
    }

    private static function belowThousand(int $number, bool $withAnd = false): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $hundreds = intdiv($number, 100);
        $below = $number % 100;

        $words = $hundreds > 0 ? self::belowHundred($hundreds).' Hundred' : '';

        if ($below > 0) {
            $words .= ($words === '' ? '' : ($withAnd ? ' and ' : ' ')).self::belowHundred($below);
        }

        return $words;
    }

    private static function belowHundred(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $word = self::TENS[intdiv($number, 10)];

        return $number % 10 > 0 ? $word.' '.self::ONES[$number % 10] : $word;
    }
}
