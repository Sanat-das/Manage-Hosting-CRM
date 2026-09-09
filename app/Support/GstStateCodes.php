<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The 38 GST state codes — the single vocabulary for every state code stored
 * by this application.
 *
 * Why this exists: `gst_settings.state_code` held numeric GST codes ('27')
 * while `customers.state_code` held two-letter codes ('WB'), and
 * GstTaxService::isIntraState() decides CGST+SGST vs IGST by comparing the two
 * for equality. Two vocabularies meant the comparison could never succeed, so
 * every customer was billed as inter-state — and with an IGST rate of 0 that
 * silently produced zero-tax invoices for everybody.
 *
 * The canonical form is the numeric GST code, zero-padded to two characters,
 * because that is what the first two digits of a GSTIN are. Anything else —
 * a legacy alpha code, an unpadded number, a full state name — is converted by
 * normalize() rather than stored.
 *
 * Codes 97 (Other Territory) and 99 (Centre Jurisdiction) are deliberately NOT
 * included: they are jurisdictions rather than states, and neither is a valid
 * place of supply for the intra/inter-state test.
 */
final class GstStateCodes
{
    /**
     * GST state code => state name. Two entries are historical and kept
     * because GSTINs issued under them still exist:
     *  - 25 Daman and Diu, merged into 26 in 2020;
     *  - 28 Andhra Pradesh, split in 2014 (the state is 37 now, Telangana 36).
     *
     * @var array<string, string>
     */
    public const CODES = [
        '01' => 'Jammu and Kashmir',
        '02' => 'Himachal Pradesh',
        '03' => 'Punjab',
        '04' => 'Chandigarh',
        '05' => 'Uttarakhand',
        '06' => 'Haryana',
        '07' => 'Delhi',
        '08' => 'Rajasthan',
        '09' => 'Uttar Pradesh',
        '10' => 'Bihar',
        '11' => 'Sikkim',
        '12' => 'Arunachal Pradesh',
        '13' => 'Nagaland',
        '14' => 'Manipur',
        '15' => 'Mizoram',
        '16' => 'Tripura',
        '17' => 'Meghalaya',
        '18' => 'Assam',
        '19' => 'West Bengal',
        '20' => 'Jharkhand',
        '21' => 'Odisha',
        '22' => 'Chhattisgarh',
        '23' => 'Madhya Pradesh',
        '24' => 'Gujarat',
        '25' => 'Daman and Diu (merged into 26)',
        '26' => 'Dadra and Nagar Haveli and Daman and Diu',
        '27' => 'Maharashtra',
        '28' => 'Andhra Pradesh (before division)',
        '29' => 'Karnataka',
        '30' => 'Goa',
        '31' => 'Lakshadweep',
        '32' => 'Kerala',
        '33' => 'Tamil Nadu',
        '34' => 'Puducherry',
        '35' => 'Andaman and Nicobar Islands',
        '36' => 'Telangana',
        '37' => 'Andhra Pradesh',
        '38' => 'Ladakh',
    ];

    /**
     * Legacy two-letter codes => GST code, for converting what is already
     * stored (customers.state_code was written by an alpha map) and for
     * accepting a hand-typed 'MH'.
     *
     * 'AP' resolves to 37, the present-day Andhra Pradesh; the pre-division 28
     * is reachable only by its number.
     *
     * @var array<string, string>
     */
    private const ALPHA = [
        'JK' => '01', 'HP' => '02', 'PB' => '03', 'CH' => '04', 'UK' => '05',
        'UT' => '05', 'HR' => '06', 'DL' => '07', 'RJ' => '08', 'UP' => '09',
        'BR' => '10', 'SK' => '11', 'AR' => '12', 'NL' => '13', 'MN' => '14',
        'MZ' => '15', 'TR' => '16', 'ML' => '17', 'AS' => '18', 'WB' => '19',
        'JH' => '20', 'OR' => '21', 'OD' => '21', 'CG' => '22', 'CT' => '22',
        'MP' => '23', 'GJ' => '24', 'DD' => '25', 'DN' => '26', 'DH' => '26',
        'MH' => '27', 'KA' => '29', 'GA' => '30', 'LD' => '31', 'KL' => '32',
        'TN' => '33', 'PY' => '34', 'PD' => '34', 'AN' => '35', 'TG' => '36',
        'TS' => '36', 'AP' => '37', 'LA' => '38', 'LH' => '38',
    ];

    /**
     * Extra spellings accepted by name, beyond CODES. Keyed lower-case.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'orissa' => '21',
        'pondicherry' => '34',
        'uttaranchal' => '05',
        'nct of delhi' => '07',
        'new delhi' => '07',
        'jammu & kashmir' => '01',
        'daman and diu' => '26',
        'daman & diu' => '26',
        'dadra and nagar haveli' => '26',
        'dadra & nagar haveli' => '26',
        'andaman and nicobar' => '35',
        'andaman & nicobar islands' => '35',
    ];

    /**
     * NOTE ON KEY TYPES: PHP canonicalises decimal array keys to integers, and
     * re-canonicalises them on every assignment — so CODES is keyed by the
     * strings '01'..'09' but the INTEGERS 10..38, and no amount of casting can
     * make an array hold '10' as a string key. Nothing may therefore return a
     * raw key: an int leaking out breaks `Rule::in` against a posted '27' and
     * the `===` in isIntraState(). codes() and normalize() cast to string;
     * iterate all() with `(string) $code` if you need the key itself.
     *
     * @return array<array-key, string> code => state name
     */
    public static function all(): array
    {
        return self::CODES;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(strval(...), array_keys(self::CODES));
    }

    public static function isValid(mixed $code): bool
    {
        return is_scalar($code) && array_key_exists((string) $code, self::CODES);
    }

    public static function name(mixed $code): ?string
    {
        return self::isValid($code) ? self::CODES[(string) $code] : null;
    }

    /**
     * Coerce anything that identifies a state into the canonical GST code, or
     * null when it identifies nothing we recognise (a foreign address, a typo,
     * the two-letter truncation the old customer code fell back to).
     *
     * Accepts: '27', 27, '7', 'MH', 'mh', 'Maharashtra', 'maharashtra '.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || (! is_scalar($value))) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        // Numeric: pad '7' to '07' before looking it up.
        if (ctype_digit($raw)) {
            $padded = str_pad($raw, 2, '0', STR_PAD_LEFT);

            return self::isValid($padded) ? $padded : null;
        }

        $upper = strtoupper($raw);

        if (isset(self::ALPHA[$upper])) {
            return self::ALPHA[$upper];
        }

        $lower = strtolower($raw);

        if (isset(self::ALIASES[$lower])) {
            return self::ALIASES[$lower];
        }

        foreach (self::CODES as $code => $name) {
            if (strtolower($name) === $lower) {
                return (string) $code;
            }
        }

        return null;
    }

    /**
     * Options for a select: code => "27 — Maharashtra".
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::CODES as $code => $name) {
            $options[(string) $code] = $code.' — '.$name;
        }

        return $options;
    }
}
