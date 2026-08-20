<?php

namespace App\Settings;

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
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'date_format' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ];
    }
}
