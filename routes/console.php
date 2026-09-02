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

// Ticket email piping. The command no-ops in seconds when Incoming Mail is
// disabled, so it is safe to schedule unconditionally; withoutOverlapping
// keeps a slow mailbox from stacking runs.
Schedule::command('tickets:fetch-mail')
    ->everyFiveMinutes()
    ->withoutOverlapping()
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
| Deliberately NOT chained: onOneServer() requires a central redis cache
| (the default file cache cannot share locks across servers) and
| runInBackground() requires pcntl/posix, which Windows PHP does not ship.
| withoutOverlapping() alone already prevents overlapping runs per machine.
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
