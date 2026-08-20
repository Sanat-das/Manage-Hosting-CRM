<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * IP address management settings (new T4.2 group: ipam).
 */
class IpamSettings extends Settings
{
    public bool $ipam_enabled = true;

    public bool $ipam_auto_allocate = true;

    public string $ipam_default_ipv4_gateway = '';

    public string $ipam_default_ipv6_prefix = '';

    public bool $ipam_allow_public_ipv6 = false;

    public int $ipam_reservation_hold_days = 14;

    public int $ipam_scan_interval_minutes = 60;

    public string $ipam_dns_reverse_zone = '';

    public int $ipam_low_capacity_warning_percent = 20;

    public bool $ipam_auto_release_unused = false;

    public int $ipam_unused_release_days = 90;

    public bool $ipam_validate_networks = true;

    public bool $ipam_vlan_tracking = false;

    public int $ipam_audit_retention_days = 365;

    public static function group(): string
    {
        return 'ipam';
    }

    public static function rules(): array
    {
        return [
            'ipam_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_auto_allocate' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_default_ipv4_gateway' => ['nullable', 'string', 'max:255'],
            'ipam_default_ipv6_prefix' => ['nullable', 'string', 'max:255'],
            'ipam_allow_public_ipv6' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_reservation_hold_days' => ['nullable', 'integer', 'min:0'],
            'ipam_scan_interval_minutes' => ['nullable', 'integer', 'min:1'],
            'ipam_dns_reverse_zone' => ['nullable', 'string', 'max:255'],
            'ipam_low_capacity_warning_percent' => ['nullable', 'integer', 'between:0,100'],
            'ipam_auto_release_unused' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_unused_release_days' => ['nullable', 'integer', 'min:0'],
            'ipam_validate_networks' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_vlan_tracking' => ['nullable', 'in:1,0,yes,no,true,false'],
            'ipam_audit_retention_days' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
