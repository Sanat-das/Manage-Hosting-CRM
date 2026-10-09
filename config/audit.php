<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit & log retention
|--------------------------------------------------------------------------
|
| DEFAULTS for the days of history kept per stream. The admin Settings → Log
| Retention tab overrides these at runtime; `logs:prune` resolves each stream
| through App\Settings\LogRetentionSettings::daysFor(), which falls back to the
| values below when no setting is stored. `null` keeps the stream forever
| (compliance records). `cron_task_runs` is NOT listed: it prunes itself
| (30 days) via App\Listeners\RecordScheduledTaskRun.
|
*/

return [

    'retention' => [
        'activity_log' => 180,
        'audit_log' => 365,
        'emails' => 90,
        'module_log' => 90,
        'domain_sync_log' => 90,
        'domain_search_logs' => 30,
        'invoice_pdf_log' => 365,
        'marketing_consent_log' => null,
    ],

];
