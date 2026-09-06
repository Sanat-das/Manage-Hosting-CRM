<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\CronTaskRun;
use App\Services\Cron\CronTaskRegistry;
use App\Services\Cron\ScheduleInspector;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the run history behind the admin Cron Jobs page from Laravel's own
 * scheduling events, and stamps the scheduler heartbeat.
 *
 * This is observability, so every handler is wrapped: a broken history write
 * must never be able to fail the scheduled work it is only reporting on.
 */
class RecordScheduledTaskRun
{
    /** Run history older than this is pruned on the hourly heartbeat. */
    private const RETENTION_DAYS = 30;

    /**
     * Task key => id of the run row this process opened. Static because the
     * container resolves a fresh listener instance per dispatched event, so
     * an instance property could not carry the id from "starting" through to
     * "finished". Entries are overwritten (not cleared) on close so a
     * ScheduledTaskFailed arriving after ScheduledTaskFinished still lands on
     * the same row.
     *
     * @var array<string, int>
     */
    private static array $openRuns = [];

    public function __construct(private readonly CronTaskRegistry $registry) {}

    /**
     * Drop the open-run map. Process-lifetime state, so it must be cleared
     * whenever the surrounding database is swapped underneath it — which is
     * every test case, but also any long-lived worker that reconnects.
     */
    public static function reset(): void
    {
        static::$openRuns = [];
    }

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $this->guard(function () use ($event): void {
            $key = $this->registry->keyFor($event->task);

            if ($key === null) {
                return;
            }

            $run = CronTaskRun::query()->create([
                'task_key' => $key,
                'status' => CronTaskRun::STATUS_RUNNING,
                'trigger' => CronTaskRun::TRIGGER_SCHEDULE,
                'started_at' => Carbon::now(),
            ]);

            static::$openRuns[$key] = (int) $run->id;
        });
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->guard(function () use ($event): void {
            // A background task has only been SPAWNED at this point — its real
            // exit code arrives later, in a separate `schedule:finish` process.
            // Closing here would record a success before the work ran.
            if ($event->task->runInBackground) {
                return;
            }

            $this->close(
                $event->task,
                exitCode: $this->exitCode($event->task),
                runtimeMs: (int) round($event->runtime * 1000),
            );
        });
    }

    public function handleBackgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->guard(function () use ($event): void {
            $this->close($event->task, exitCode: $this->exitCode($event->task), runtimeMs: null);
        });
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->guard(function () use ($event): void {
            $this->close(
                $event->task,
                exitCode: $this->exitCode($event->task),
                runtimeMs: null,
                message: $event->exception->getMessage(),
                failed: true,
            );
        });
    }

    public function handleSkipped(ScheduledTaskSkipped $event): void
    {
        $this->guard(function () use ($event): void {
            $key = $this->registry->keyFor($event->task);

            if ($key === null) {
                return;
            }

            // Only DUE events reach the skip branch of ScheduleRunCommand, so
            // this records "it was time to run and we deliberately did not" —
            // one row per missed slot, not one per idle minute.
            CronTaskRun::query()->create([
                'task_key' => $key,
                'status' => CronTaskRun::STATUS_SKIPPED,
                'trigger' => CronTaskRun::TRIGGER_SCHEDULE,
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
                'message' => 'Skipped — task disabled, paused, or blocked by an overlap guard.',
            ]);
        });
    }

    /**
     * Scheduler heartbeat. Derived from the command itself rather than from
     * task activity: a schedule with nothing due for hours still proves the
     * cron entry is alive, which is exactly the failure the page must catch.
     */
    public function handleCommandFinished(CommandFinished $event): void
    {
        if ($event->command !== 'schedule:run') {
            return;
        }

        $this->guard(function (): void {
            Cache::forever(ScheduleInspector::HEARTBEAT_KEY, Carbon::now()->toIso8601String());

            $this->pruneOldRunsIfDue();
        });
    }

    /**
     * Retention cleanup independent of the heartbeat minute — the old
     * minute===0 guard could miss an entire hour if the scheduler never
     * ticked on :00 (e.g. machine was asleep, schedule:run took >60s and
     * skipped that tick). We now use a cache-guarded hourly throttle so
     * pruning happens roughly once an hour regardless of which minute we
     * happen to land on, and it also self-heals if a tick was missed.
     */
    private function pruneOldRunsIfDue(): void
    {
        $lastPruneKey = 'cron.runs.last_prune_at';
        try {
            $lastPruneRaw = Cache::get($lastPruneKey);
            $lastPrune = $lastPruneRaw !== null ? Carbon::parse($lastPruneRaw) : null;
            if ($lastPrune !== null && $lastPrune->diffInMinutes(Carbon::now(), absolute: true) < 60) {
                // Also keep the legacy minute-0 fast path: if we ARE on :00 we
                // allow pruning even within the hour to preserve old behaviour
                // for tests that freeze time at :00.
                if (Carbon::now()->minute !== 0) {
                    return;
                }
            }
        } catch (Throwable) {
            // If cache is unavailable, still attempt pruning — missing a prune
            // is worse than pruning too often.
        }

        try {
            CronTaskRun::query()
                ->where('started_at', '<', Carbon::now()->subDays(self::RETENTION_DAYS))
                ->delete();
            Cache::forever($lastPruneKey, Carbon::now()->toIso8601String());
        } catch (Throwable $e) {
            Log::warning('Cron run history prune failed.', ['error' => $e->getMessage()]);
        }
    }

    private function close(
        ScheduledEvent $task,
        ?int $exitCode,
        ?int $runtimeMs,
        ?string $message = null,
        bool $failed = false,
    ): void {
        $key = $this->registry->keyFor($task);

        if ($key === null) {
            return;
        }

        $run = $this->openRunFor($key);

        if ($run === null) {
            return;
        }

        $finishedAt = Carbon::now();
        $isSuccess = ! $failed && ($exitCode === null || $exitCode === 0);

        $run->forceFill([
            'status' => $isSuccess
                ? CronTaskRun::STATUS_SUCCESS
                : CronTaskRun::STATUS_FAILED,
            'exit_code' => $exitCode,
            'finished_at' => $finishedAt,
            'runtime_ms' => $runtimeMs ?? ($run->started_at !== null
                ? max(0, (int) $run->started_at->diffInMilliseconds($finishedAt, absolute: true))
                : null),
            'message' => $message ?? $run->message,
        ])->save();

        // Explicit health for tickets:fetch-mail — the scheduler heartbeat
        // alone would look healthy even while IMAP is dead, because
        // schedule:run ticks every minute regardless of whether the mailbox
        // was reachable. Store last success/failure separately so the admin
        // page can surface "scheduler ticking but tickets fetch dead".
        if ($key === 'tickets:fetch-mail') {
            try {
                if ($isSuccess) {
                    Cache::forever(ScheduleInspector::TICKETS_HEALTH_KEY, $finishedAt->toIso8601String());
                    Cache::forever(ScheduleInspector::TICKETS_HEALTH_STATUS_KEY, 'ok');
                } else {
                    // Do not overwrite last_success on failure, but record status.
                    Cache::forever(ScheduleInspector::TICKETS_HEALTH_STATUS_KEY, 'failed');
                    Cache::forever(ScheduleInspector::TICKETS_HEALTH_FAILED_AT_KEY, $finishedAt->toIso8601String());
                }
            } catch (Throwable $e) {
                Log::warning('Tickets mail health could not be recorded.', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * The row this process opened, falling back to the newest still-running
     * row for the key — the background case, where `schedule:finish` reports
     * the result from a different process than the one that opened it.
     */
    private function openRunFor(string $key): ?CronTaskRun
    {
        if (isset(static::$openRuns[$key])) {
            // The task_key guard stops a stale id (from a previous database or
            // a recycled auto-increment) from closing an unrelated row.
            $run = CronTaskRun::query()
                ->whereKey(static::$openRuns[$key])
                ->where('task_key', $key)
                ->first();

            if ($run !== null) {
                return $run;
            }
        }

        return CronTaskRun::query()
            ->where('task_key', $key)
            ->where('status', CronTaskRun::STATUS_RUNNING)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Event::$exitCode is only meaningful for command events; a callback
     * task leaves it null and must not be reported as a non-zero exit.
     */
    private function exitCode(ScheduledEvent $task): ?int
    {
        return $task->exitCode === null ? null : (int) $task->exitCode;
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Cron run history could not be recorded.', ['error' => $e->getMessage()]);
        }
    }
}
