<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Scheduled-task / cron settings (new T4.2 group: cron).
 */
class CronSettings extends Settings
{
    public bool $cron_scheduler_enabled = true;

    public bool $cron_heartbeat_enabled = true;

    public string $cron_domain_expiry_check = 'daily';

    public string $cron_overdue_invoice_check = 'daily';

    public string $cron_backup_check = 'weekly';

    public string $cron_usage_sync = 'hourly';

    public string $cron_pricing_sync = 'daily';

    public string $cron_report_generation = 'daily';

    public int $cron_log_cleanup_days = 30;

    public int $cron_lock_timeout_minutes = 60;

    public int $cron_max_runtime_minutes = 30;

    public bool $cron_notify_on_failure = true;

    public string $cron_notify_email = '';

    public static function group(): string
    {
        return 'cron';
    }

    public static function rules(): array
    {
        return [
            'cron_scheduler_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'cron_heartbeat_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'cron_domain_expiry_check' => ['nullable', 'string', 'max:50'],
            'cron_overdue_invoice_check' => ['nullable', 'string', 'max:50'],
            'cron_backup_check' => ['nullable', 'string', 'max:50'],
            'cron_usage_sync' => ['nullable', 'string', 'max:50'],
            'cron_pricing_sync' => ['nullable', 'string', 'max:50'],
            'cron_report_generation' => ['nullable', 'string', 'max:50'],
            'cron_log_cleanup_days' => ['nullable', 'integer', 'min:0'],
            'cron_lock_timeout_minutes' => ['nullable', 'integer', 'min:1'],
            'cron_max_runtime_minutes' => ['nullable', 'integer', 'min:1'],
            'cron_notify_on_failure' => ['nullable', 'in:1,0,yes,no,true,false'],
            'cron_notify_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
