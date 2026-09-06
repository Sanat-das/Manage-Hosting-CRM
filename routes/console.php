<?php

use App\Services\Cron\CronTaskRegistry;
// The facade registers tasks; the underlying Schedule instance is what holds
// them, and the two class names differ — alias so the gate loop below reads
// the registry rather than the facade.
use Illuminate\Console\Scheduling\Schedule as ScheduleRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Jobs\RollupHourlyAggregates;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:recurring')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('domains:expiry-check --days=30')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('hosting:usage-sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('app:cleanup --days=90')
    ->weekly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('ssl:check-expiry --days=30')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('invoices:overdue-check')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reports:send-scheduled --days=7')
    ->weeklyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('domains:sync-pricing')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

// Ticket email piping. Mailboxes are per-department (Support > Departments);
// with none configured the command reports "nothing to poll" and exits in
// seconds, so it is safe to schedule unconditionally. withoutOverlapping keeps
// a slow mailbox from stacking runs.
// Expires after 10 min (2 missed polls) so a crashed/ hung IMAP lock does
// not block the mailbox for 24h (Laravel's default 1440 min). Uses its own
// mutex name — distinct from queue-emails-cron — so the two email-related
// tasks never block each other even though both touch mail.
Schedule::command('tickets:fetch-mail')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

/*
|--------------------------------------------------------------------------
| SNMP Monitor polling pipeline (three-module-split task 5)
|--------------------------------------------------------------------------
|
| Both a scheduler tick AND a queue worker must be running for collection
| to flow: `php artisan schedule:run` every minute (Windows Task Scheduler
| or cron) enqueues the batches, and `php artisan queue:work --queue=snmp-poll
| --tries=1 --timeout=120 --max-jobs=500 --max-time=3600` executes them.
|
| Deliberately NOT using onOneServer(): it requires a central redis cache
| (the default file cache cannot share locks across servers).
| withoutOverlapping() alone already prevents overlapping runs per machine.
|
| runInBackground() is simply not needed here - these dispatch jobs and return
| immediately. It is NOT unavailable on Windows: Illuminate's CommandBuilder
| has an explicit `start /b cmd /v:on /c` branch for windows_os() and never
| touches pcntl/posix, which is why the mail/billing tasks below use it.
|
*/

Schedule::call(fn () => PollHostBatch::dispatchDue())
    ->everyMinute()
    ->name('snmp-poll-dispatch-due')
    ->withoutOverlapping();

Schedule::call(fn () => RollupHourlyAggregates::dispatch())
    ->hourlyAt(5)
    ->name('snmp-rollup-hourly')
    ->withoutOverlapping();

Schedule::command('snmp:maintain-partitions')
    ->dailyAt('00:10')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Linux VPS SSH pruner (stale opened sessions)
|--------------------------------------------------------------------------
|
| Browser crashes before /close leave rows stuck as 'opened' and cache
| queues (ssh-console.in/ctrl/act.{token}) lingering until QUEUE_TTL
| (3600s). The pruner closes rows older than 35 min (see MAX_LIFETIME
| 1800s + IDLE_TIMEOUT 600s ≈ 40 min worst case; 35 min is the safe
| threshold) via finalize('closed','Pruned: stale') and purges the three
| cache keys. Runs every 15 minutes so no stale row survives >50 min.
|
| Requires `php artisan schedule:run` every minute (cron / Task Scheduler).
|
*/
Schedule::command('ssh:prune')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/**
 * Atomic helper for cron-emails-health.json — both the drain callbacks and
 * the heartbeat closure write the same file and can overlap (queue:work every
 * minute vs heartbeat every five). Without locking one write can corrupt the
 * other's JSON. FILE_APPEND is never used; we replace the file atomically
 * with LOCK_EX so readers either see the old or the new content, never a
 * half-write.
 */
if (! function_exists('__cron_atomic_write_health')) {
    function __cron_atomic_write_health(array $patch, bool $replace = false): void
    {
        $path = storage_path('app/cron-emails-health.json');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Open with c+ so we can lock even when the file does not yet exist.
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                return;
            }
            $existing = [];
            $raw = stream_get_contents($handle);
            if ($raw !== false && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existing = $decoded;
                }
            }
            $payload = $replace ? $patch : array_merge($existing, $patch);
            // Truncate + rewrite atomically under the lock.
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($payload, JSON_PRETTY_PRINT));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}

// `default` is polled alongside `emails`: it had no worker at all, so any job
// queued without an explicit queue name sat in the table forever. Order is
// priority order — mail drains first.
Schedule::command('queue:work --queue=emails,default --sleep=3 --tries=3 --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('queue-emails-cron')
    ->onSuccess(function () {
        try {
            $remaining = \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'emails')->count();
            $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
            \Illuminate\Support\Facades\Log::info('queue-emails-cron drained', ['remaining' => $remaining, 'failed' => $failed]);
            __cron_atomic_write_health(['last_run' => now()->toIso8601String(), 'remaining' => $remaining, 'failed' => $failed, 'status' => 'ok']);
        } catch (\Throwable $e) {
        }
    })
    ->onFailure(function () {
        try {
            \Illuminate\Support\Facades\Log::warning('queue-emails-cron failed');
            __cron_atomic_write_health(['last_run' => now()->toIso8601String(), 'status' => 'failed']);
        } catch (\Throwable $e) {
        }
    });

Schedule::call(function () {
    try {
        $jobs = \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'emails')->count();
        $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        $queueOk = $jobs < 20 && $failed === 0;
        \Illuminate\Support\Facades\Log::info('emails-queue-heartbeat', ['jobs' => $jobs, 'failed' => $failed, 'ok' => $queueOk]);
        __cron_atomic_write_health([
            'heartbeat_at' => now()->toIso8601String(),
            'heartbeat_jobs' => $jobs,
            'heartbeat_failed' => $failed,
            'heartbeat_ok' => $queueOk,
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('emails-queue-heartbeat failed', ['error' => $e->getMessage()]);
    }
})->everyFiveMinutes()->name('emails-queue-heartbeat')->withoutOverlapping(10);

/*
|--------------------------------------------------------------------------
| Admin enable/disable gate (Cron Jobs page)
|--------------------------------------------------------------------------
|
| Applied as a filter to every task declared above, so the admin page can
| take one task out of rotation without a code change or a deploy. Kept as a
| loop at the end of the file rather than a ->skip() on each definition so a
| newly added schedule is covered automatically instead of silently escaping
| the gate.
|
| Default is ENABLED: only an explicit `false` row in cron_tasks disables a
| task, and any failure to read that table (pre-install, mid-migration) lets
| the schedule run. The management layer must never be the reason cron stops.
|
| Skipped tasks are still recorded — ScheduleRunCommand dispatches
| ScheduledTaskSkipped for a due-but-filtered event, so the page shows the
| slots a disabled task went through.
|
*/

$cronRegistry = app(CronTaskRegistry::class);

foreach (app(ScheduleRegistry::class)->events() as $scheduledEvent) {
    $cronKey = $cronRegistry->keyFor($scheduledEvent);

    // An unnamed closure has no stable key, so it can never be disabled from
    // the page and is left to run unconditionally.
    if ($cronKey === null) {
        continue;
    }

    $scheduledEvent->skip(fn (): bool => ! app(CronTaskRegistry::class)->isEnabled($cronKey));
}

/*
|--------------------------------------------------------------------------
| Admin editable schedule overrides (Cron Jobs page)
|--------------------------------------------------------------------------
|
| When an operator customises the timing of a task via the admin UI, the
| override is stored in cron_tasks. The code declaration remains the default,
| but on every bootstrap the stored expression / timezone / description is
| applied on top, so both `schedule:run` and the admin page see the same
| effective schedule.
|
| Validation on save guarantees the expression is a 5-field cron and the
| timezone is a PHP identifier; still, we guard the apply path so a bad row
| never kills the scheduler.
|
*/

try {
    $overrides = \App\Models\CronTask::query()
        ->whereNotNull('expression')
        ->orWhereNotNull('timezone')
        ->orWhereNotNull('description')
        ->get()
        ->keyBy('key');

    if ($overrides->isNotEmpty()) {
        foreach (app(ScheduleRegistry::class)->events() as $scheduledEvent) {
            $cronKey = $cronRegistry->keyFor($scheduledEvent);
            if ($cronKey === null || ! isset($overrides[$cronKey])) {
                continue;
            }

            $row = $overrides[$cronKey];

            if (is_string($row->expression) && trim($row->expression) !== '') {
                try {
                    $scheduledEvent->cron(trim($row->expression));
                } catch (Throwable) {
                    // Invalid expression must never break the schedule.
                }
            }

            if (is_string($row->timezone) && trim($row->timezone) !== '') {
                try {
                    $scheduledEvent->timezone(trim($row->timezone));
                } catch (Throwable) {
                }
            }

            if (is_string($row->description) && trim($row->description) !== '') {
                $scheduledEvent->description(trim($row->description));
            }
        }
    }
} catch (Throwable) {
    // No table yet (pre-install / mid-migration) — never let the override
    // layer stop the schedule from running.
}
