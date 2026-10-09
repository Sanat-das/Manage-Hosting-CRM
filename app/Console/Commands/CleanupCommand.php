<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use App\Settings\LogRetentionSettings;
use Illuminate\Console\Command;

/**
 * Cleanup old records (activity logs, email logs, etc.).
 *
 * With `--days` provided, both the activity and email logs use that single
 * window. Without it, each stream uses its window from LogRetentionSettings
 * (Settings → Log Retention / config/audit.php defaults), so a manual run
 * matches the scheduled `logs:prune` policy. A null window keeps that stream
 * forever and nothing is deleted for it.
 */
class CleanupCommand extends Command
{
    protected $signature = 'app:cleanup {--days= : Override the retention window in days for activity and email logs}';

    protected $description = 'Purge old activity logs, email logs, and stale data';

    public function handle(): int
    {
        $days = $this->option('days');

        // A non-numeric/zero/negative override casts to 0 and would become a
        // subDays(0) wipe of BOTH streams; reject it before any delete runs.
        if ($days !== null && (! ctype_digit((string) $days) || (int) $days < 1)) {
            $this->error('--days must be a positive integer.');

            return self::FAILURE;
        }

        if ($days !== null) {
            $activityDays = (int) $days;
            $emailDays = (int) $days;
        } else {
            $activityDays = LogRetentionSettings::daysFor('activity_log');
            $emailDays = LogRetentionSettings::daysFor('emails');
        }

        $activityDeleted = $activityDays === null
            ? 0
            : ActivityLog::where('created_at', '<', now()->subDays($activityDays))->delete();

        $emailDeleted = $emailDays === null
            ? 0
            : EmailLog::where('created_at', '<', now()->subDays($emailDays))->delete();

        if ($days !== null) {
            $this->info("Cleanup complete (older than {$days} days):");
        } else {
            $this->info('Cleanup complete (per-stream retention):');

            if ($activityDays === null) {
                $this->line('  activity_log: kept forever (no retention window)');
            }
            if ($emailDays === null) {
                $this->line('  emails: kept forever (no retention window)');
            }
        }

        $this->line("  Activity logs deleted: {$activityDeleted}");
        $this->line("  Email logs deleted: {$emailDeleted}");

        return self::SUCCESS;
    }
}
