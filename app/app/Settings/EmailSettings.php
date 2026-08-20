<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * SMTP / mail delivery settings (legacy `settings` group: email).
 */
class EmailSettings extends Settings
{
    public string $smtp_host = '';

    public int $smtp_port = 587;

    public string $smtp_username = '';

    public string $smtp_password = '';

    public string $smtp_encryption = 'tls';

    public static function group(): string
    {
        return 'email';
    }

    public static function rules(): array
    {
        return [
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl'],
        ];
    }
}
