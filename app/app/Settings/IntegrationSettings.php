<?php

namespace App\Settings;

use App\Settings\Casts\EncryptedCast;
use Spatie\LaravelSettings\Settings;

/**
 * Control-panel / registrar integration settings (new T4.2 group:
 * integration). Credential values are stored encrypted via EncryptedCast
 * (Crypt::encryptString at rest, decrypt on read; blanks stored plain).
 */
class IntegrationSettings extends Settings
{
    public bool $cpanel_enabled = false;

    public string $cpanel_host = '';

    public int $cpanel_port = 2083;

    public string $cpanel_api_token = '';

    public bool $plesk_enabled = false;

    public string $plesk_host = '';

    public int $plesk_port = 8443;

    public string $plesk_username = '';

    public string $plesk_password = '';

    public bool $resellerclub_enabled = false;

    public string $resellerclub_api_id = '';

    public string $resellerclub_api_key = '';

    public string $resellerclub_username = '';

    public static function group(): string
    {
        return 'integration';
    }

    public static function casts(): array
    {
        return [
            'cpanel_api_token' => EncryptedCast::class,
            'plesk_password' => EncryptedCast::class,
            'resellerclub_api_key' => EncryptedCast::class,
        ];
    }

    public static function rules(): array
    {
        return [
            'cpanel_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'cpanel_host' => ['nullable', 'string', 'max:255'],
            'cpanel_port' => ['nullable', 'integer', 'between:1,65535'],
            'cpanel_api_token' => ['nullable', 'string', 'max:500'],
            'plesk_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'plesk_host' => ['nullable', 'string', 'max:255'],
            'plesk_port' => ['nullable', 'integer', 'between:1,65535'],
            'plesk_username' => ['nullable', 'string', 'max:255'],
            'plesk_password' => ['nullable', 'string', 'max:500'],
            'resellerclub_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'resellerclub_api_id' => ['nullable', 'string', 'max:255'],
            'resellerclub_api_key' => ['nullable', 'string', 'max:500'],
            'resellerclub_username' => ['nullable', 'string', 'max:255'],
        ];
    }
}
