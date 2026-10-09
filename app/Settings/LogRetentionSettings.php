<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;
use Throwable;

/**
 * Log-retention windows for the DB-backed audit/log streams (group: log_retention).
 *
 * The property defaults mirror config/audit.php, which the admin
 * Settings → Log Retention tab overrides at runtime. `daysFor()` is the single
 * resolution point: `logs:prune` and `app:cleanup` both read a table's window
 * through it, so config/audit.php stays the fallback and a stored setting wins.
 */
final class LogRetentionSettings extends Settings
{
    public int $activity_retention_days = 180;

    public int $audit_retention_days = 365;

    public int $email_retention_days = 90;

    public int $module_retention_days = 90;

    public int $domain_sync_retention_days = 90;

    public int $domain_search_retention_days = 30;

    public int $invoice_pdf_retention_days = 365;

    /** 0 = keep forever. */
    public int $consent_retention_days = 0;

    /**
     * Retention table => owning property.
     *
     * @var array<string, string>
     */
    private const PROPERTY_BY_TABLE = [
        'activity_log' => 'activity_retention_days',
        'audit_log' => 'audit_retention_days',
        'emails' => 'email_retention_days',
        'module_log' => 'module_retention_days',
        'domain_sync_log' => 'domain_sync_retention_days',
        'domain_search_logs' => 'domain_search_retention_days',
        'invoice_pdf_log' => 'invoice_pdf_retention_days',
        'marketing_consent_log' => 'consent_retention_days',
    ];

    public static function group(): string
    {
        return 'log_retention';
    }

    public static function rules(): array
    {
        return [
            'activity_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'audit_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'email_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'module_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'domain_sync_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'domain_search_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'invoice_pdf_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'consent_retention_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ];
    }

    /**
     * Retention window in days for a log table, or null to keep it forever.
     *
     * The stored setting wins; when the class cannot be resolved the
     * config/audit.php default is the fallback, where a null default also means
     * "keep forever". Consent uses `0` as the shipped sentinel for "forever";
     * every other stream treats a non-positive stored window as a fault and
     * falls back to the config default — a stray `0` must never become a
     * `subDays(0)` delete-everything prune (it is only reachable by writing
     * around the min:1 validation).
     */
    public static function daysFor(string $table): ?int
    {
        $property = self::PROPERTY_BY_TABLE[$table] ?? null;

        $fallback = config('audit.retention.'.$table);
        $fallback = $fallback === null ? null : (int) $fallback;

        if ($property === null) {
            return $fallback;
        }

        try {
            $days = (int) app(self::class)->{$property};
        } catch (Throwable $e) {
            report($e);

            return $fallback;
        }

        if ($property === 'consent_retention_days') {
            return $days > 0 ? $days : null;
        }

        return $days > 0 ? $days : $fallback;
    }
}
