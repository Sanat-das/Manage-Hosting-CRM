<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The country list every address form on this application offers.
 *
 * Why this exists: the same ten-entry array
 * `['India','United States',…,'Other']` was pasted into six Blade views
 * (customers create/edit, users create/edit, register, client profile, the
 * admin order form and the Settings company address). Two problems came with
 * it. A customer outside those nine countries had no option but the literal
 * string `Other`, which is what then got stored in `users.country` and printed
 * on their invoice; and any correction had to be made in six places, so the
 * lists had already begun to drift apart.
 *
 * VALUES ARE NAMES, NOT ISO CODES. Every `users.country`,
 * `datacenters.country` and `settings.company_country` row already holds an
 * English country name, and those columns are rendered directly onto invoices.
 * Switching the stored form to `IN` would need a migration across three tables
 * plus a display map, to buy nothing — so the name stays canonical and the ISO
 * code is not stored at all.
 */
final class Countries
{
    public const DEFAULT = 'India';

    /**
     * ISO 3166-1 English short names, alphabetical.
     *
     * @var list<string>
     */
    public const NAMES = [
        'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola',
        'Antigua and Barbuda', 'Argentina', 'Armenia', 'Australia', 'Austria',
        'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados',
        'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan',
        'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei',
        'Bulgaria', 'Burkina Faso', 'Burundi', 'Cabo Verde', 'Cambodia',
        'Cameroon', 'Canada', 'Central African Republic', 'Chad', 'Chile',
        'China', 'Colombia', 'Comoros', 'Congo', 'Congo (Democratic Republic)',
        'Costa Rica', 'Croatia', 'Cuba', 'Cyprus', 'Czechia',
        'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'Ecuador',
        'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia',
        'Eswatini', 'Ethiopia', 'Fiji', 'Finland', 'France',
        'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana',
        'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
        'Guyana', 'Haiti', 'Honduras', 'Hong Kong', 'Hungary',
        'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq',
        'Ireland', 'Israel', 'Italy', 'Ivory Coast', 'Jamaica',
        'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati',
        'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon',
        'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania',
        'Luxembourg', 'Macao', 'Madagascar', 'Malawi', 'Malaysia',
        'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Mauritania',
        'Mauritius', 'Mexico', 'Micronesia', 'Moldova', 'Monaco',
        'Mongolia', 'Montenegro', 'Morocco', 'Mozambique', 'Myanmar',
        'Namibia', 'Nauru', 'Nepal', 'Netherlands', 'New Zealand',
        'Nicaragua', 'Niger', 'Nigeria', 'North Korea', 'North Macedonia',
        'Norway', 'Oman', 'Pakistan', 'Palau', 'Palestine',
        'Panama', 'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines',
        'Poland', 'Portugal', 'Qatar', 'Romania', 'Russia',
        'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia',
        'Saint Vincent and the Grenadines', 'Samoa',
        'San Marino', 'Sao Tome and Principe', 'Saudi Arabia', 'Senegal', 'Serbia',
        'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia',
        'Solomon Islands', 'Somalia', 'South Africa', 'South Korea', 'South Sudan',
        'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Sweden',
        'Switzerland', 'Syria', 'Taiwan', 'Tajikistan', 'Tanzania',
        'Thailand', 'Timor-Leste', 'Togo', 'Tonga', 'Trinidad and Tobago',
        'Tunisia', 'Turkey', 'Turkmenistan', 'Tuvalu', 'Uganda',
        'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay',
        'Uzbekistan', 'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam',
        'Yemen', 'Zambia', 'Zimbabwe',
    ];

    /**
     * Countries this application can offer a subdivision list for.
     *
     * Only India, and only because GstStateCodes exists — and it exists for
     * tax, not for addressing. Adding a second country here means shipping its
     * subdivision table first; until then every other country's state field
     * stays free text, because a partial list refuses valid addresses.
     *
     * @var list<string>
     */
    private const WITH_SUBDIVISIONS = [self::DEFAULT];

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return self::NAMES;
    }

    public static function isValid(mixed $country): bool
    {
        return is_string($country) && in_array($country, self::NAMES, true);
    }

    /**
     * The list to render, with `$stored` prepended when it is not one of ours.
     *
     * A stored value missing from the options is not merely unselected — the
     * browser shows the first option instead, and the next save silently
     * rewrites the row. Legacy rows hold exactly that: the literal `Other`
     * written by the old ten-country list. Keeping the value as its own option
     * means editing a customer's phone number cannot quietly relocate them to
     * Afghanistan.
     *
     * @return list<string>
     */
    public static function optionsFor(?string $stored): array
    {
        $stored = trim((string) $stored);

        if ($stored === '' || self::isValid($stored)) {
            return self::NAMES;
        }

        return array_merge([$stored], self::NAMES);
    }

    public static function hasSubdivisions(?string $country): bool
    {
        return $country !== null && in_array($country, self::WITH_SUBDIVISIONS, true);
    }

    /**
     * Subdivision names for a country, or an empty list when we have none —
     * which the state field reads as "render a text box".
     *
     * @return list<string>
     */
    public static function subdivisions(?string $country): array
    {
        return $country === self::DEFAULT ? GstStateCodes::currentNames() : [];
    }

    /**
     * The whole subdivision map, for the browser-side toggle.
     *
     * @return array<string, list<string>>
     */
    public static function subdivisionMap(): array
    {
        $map = [];

        foreach (self::WITH_SUBDIVISIONS as $country) {
            $map[$country] = self::subdivisions($country);
        }

        return $map;
    }
}
