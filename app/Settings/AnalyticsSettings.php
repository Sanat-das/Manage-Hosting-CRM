<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Analytics / reporting settings (new T4.2 group: analytics).
 */
class AnalyticsSettings extends Settings
{
    public bool $analytics_enabled = false;

    public string $analytics_tracking_code = '';

    public bool $analytics_track_admin = false;

    public int $analytics_retention_days = 180;

    public string $analytics_dashboard_widgets = '';

    public bool $analytics_export_enabled = false;

    public bool $analytics_anonymize_ip = true;

    public bool $analytics_event_tracking = true;

    public bool $analytics_privacy_consent = false;

    public string $analytics_report_email = '';

    public bool $analytics_daily_report = false;

    public bool $analytics_weekly_report = false;

    public static function group(): string
    {
        return 'analytics';
    }

    public static function rules(): array
    {
        return [
            'analytics_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_tracking_code' => ['nullable', 'string', 'max:255'],
            'analytics_track_admin' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_retention_days' => ['nullable', 'integer', 'min:0'],
            'analytics_dashboard_widgets' => ['nullable', 'string', 'max:1000'],
            'analytics_export_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_anonymize_ip' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_event_tracking' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_privacy_consent' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_report_email' => ['nullable', 'email', 'max:255'],
            'analytics_daily_report' => ['nullable', 'in:1,0,yes,no,true,false'],
            'analytics_weekly_report' => ['nullable', 'in:1,0,yes,no,true,false'],
        ];
    }
}
