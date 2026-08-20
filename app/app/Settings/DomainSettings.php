<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Domain / registrar settings (new T4.2 group: domain).
 */
class DomainSettings extends Settings
{
    public string $domain_default_registrar = '';

    public bool $domain_auto_registration = false;

    public bool $domain_transfer_enabled = false;

    public bool $domain_transfer_lock = true;

    public int $domain_transfer_lock_days = 60;

    public string $domain_nameserver1 = '';

    public string $domain_nameserver2 = '';

    public string $domain_nameserver3 = '';

    public string $domain_nameserver4 = '';

    public bool $domain_dns_enabled = true;

    public string $domain_dns_provider = '';

    public bool $domain_whois_privacy = false;

    public string $domain_pricing_tier = 'standard';

    public int $domain_renewal_reminder_days = 30;

    public static function group(): string
    {
        return 'domain';
    }

    public static function rules(): array
    {
        return [
            'domain_default_registrar' => ['nullable', 'string', 'max:255'],
            'domain_auto_registration' => ['nullable', 'in:1,0,yes,no,true,false'],
            'domain_transfer_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'domain_transfer_lock' => ['nullable', 'in:1,0,yes,no,true,false'],
            'domain_transfer_lock_days' => ['nullable', 'integer', 'min:0'],
            'domain_nameserver1' => ['nullable', 'string', 'max:255'],
            'domain_nameserver2' => ['nullable', 'string', 'max:255'],
            'domain_nameserver3' => ['nullable', 'string', 'max:255'],
            'domain_nameserver4' => ['nullable', 'string', 'max:255'],
            'domain_dns_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'domain_dns_provider' => ['nullable', 'string', 'max:255'],
            'domain_whois_privacy' => ['nullable', 'in:1,0,yes,no,true,false'],
            'domain_pricing_tier' => ['nullable', 'string', 'max:50'],
            'domain_renewal_reminder_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
