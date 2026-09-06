<?php

namespace App\Settings;

use Illuminate\Validation\Rule;
use Spatie\LaravelSettings\Settings;

/**
 * Portal / company-wide settings (legacy `settings` group: general).
 */
class GeneralSettings extends Settings
{
    public string $company_name = '';

    public string $company_email = '';

    public string $company_phone = '';

    public string $company_address = '';

    // Ecommerce sundered address — mirrors customer phone/address structure (customer User model)
    public string $company_address_line1 = '';

    public string $company_address_line2 = '';

    public string $company_city = '';

    public string $company_state = '';

    public string $company_postcode = '';

    public string $company_country = 'India';

    public string $date_format = 'Y-m-d';

    public string $timezone = 'Asia/Kolkata';

    public static function group(): string
    {
        return 'general';
    }

    public static function rules(): array
    {
        return [
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            // International phone: optional +, digits, spaces, dashes, parentheses, dots. 7-20 digits, up to 50 chars. Mirrors customer phone validation.
            'company_phone' => ['nullable', 'string', 'max:50', 'regex:/^[\+\d][\d\s\-\.\(\)]{6,49}$/'],
            // Multiline address (invoice header). Newlines preserved, 500 chars, rendered as textarea.
            'company_address' => ['nullable', 'string', 'max:500'],
            // Sundered ecommerce address — same limits as customer create (User model)
            'company_address_line1' => ['nullable', 'string', 'max:255'],
            'company_address_line2' => ['nullable', 'string', 'max:255'],
            'company_city' => ['nullable', 'string', 'max:100'],
            'company_state' => ['nullable', 'string', 'max:100'],
            'company_postcode' => ['nullable', 'string', 'max:20'],
            'company_country' => ['nullable', 'string', 'max:100'],
            'date_format' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', Rule::in(\DateTimeZone::listIdentifiers())],
        ];
    }
}
