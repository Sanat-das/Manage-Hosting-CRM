<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\EmailLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cleanup old records (activity logs, email logs, old tickets, etc.).
 */
class CleanupCommand extends Command
{
    protected $signature = 'app:cleanup {--days=90 : Delete records older than N days}';
    protected $description = 'Purge old activity logs, email logs, and stale data';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $activityDeleted = ActivityLog::where('created_at', '<', $cutoff)->delete();
        $emailDeleted = EmailLog::where('created_at', '<', $cutoff)->delete();

        $this->info("Cleanup complete (older than {$days} days):");
        $this->line("  Activity logs deleted: {$activityDeleted}");
        $this->line("  Email logs deleted: {$emailDeleted}");

        return self::SUCCESS;
    }
}
