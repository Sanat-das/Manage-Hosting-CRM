<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Hosting / provisioning settings (new T4.2 group: hosting).
 */
class HostingSettings extends Settings
{
    public string $hosting_default_panel = 'cpanel';

    public string $hosting_default_server_group = '';

    public bool $hosting_auto_provision = false;

    public int $hosting_provision_retries = 3;

    public bool $hosting_suspend_on_overdue = false;

    public int $hosting_suspend_after_days = 7;

    public int $hosting_terminate_after_days = 30;

    public bool $hosting_unsuspend_on_payment = true;

    public bool $hosting_allow_account_creation = true;

    public int $hosting_max_accounts_per_server = 0;

    public string $hosting_documentation_url = '';

    public string $hosting_terms_url = '';

    public bool $hosting_welcome_email_enabled = true;

    public bool $hosting_backup_enabled = false;

    public static function group(): string
    {
        return 'hosting';
    }

    public static function rules(): array
    {
        return [
            'hosting_default_panel' => ['nullable', 'string', 'max:50'],
            'hosting_default_server_group' => ['nullable', 'string', 'max:255'],
            'hosting_auto_provision' => ['nullable', 'in:1,0,yes,no,true,false'],
            'hosting_provision_retries' => ['nullable', 'integer', 'min:0'],
            'hosting_suspend_on_overdue' => ['nullable', 'in:1,0,yes,no,true,false'],
            'hosting_suspend_after_days' => ['nullable', 'integer', 'min:0'],
            'hosting_terminate_after_days' => ['nullable', 'integer', 'min:0'],
            'hosting_unsuspend_on_payment' => ['nullable', 'in:1,0,yes,no,true,false'],
            'hosting_allow_account_creation' => ['nullable', 'in:1,0,yes,no,true,false'],
            'hosting_max_accounts_per_server' => ['nullable', 'integer', 'min:0'],
            'hosting_documentation_url' => ['nullable', 'string', 'max:500'],
            'hosting_terms_url' => ['nullable', 'string', 'max:500'],
            'hosting_welcome_email_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'hosting_backup_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
        ];
    }
}
