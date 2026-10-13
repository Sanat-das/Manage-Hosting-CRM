<?php

declare(strict_types=1);

namespace App\ViewModels\Admin;

use App\Models\CronTaskRun;
use App\Services\Cron\ScheduleInspector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * View data for the admin dashboard "System health" widget.
 *
 * Puts three facts an operator otherwise has to hunt for on one card: the
 * scheduler heartbeat (today only on the cron page), failed jobs (today only
 * readable through `cron:emails-health`) and the queue depth.
 *
 * Every read is defensive — try/catch(Throwable) behind a Schema::hasTable()
 * guard, mirroring DashboardController — so a missing `jobs`, `failed_jobs`
 * or `cron_task_runs` table degrades to 0 / null instead of throwing on a
 * fresh or partially migrated install.
 *
 * Scheduler health is owned by ScheduleInspector, the single source of truth
 * for the `cron.scheduler.last_tick` heartbeat and its staleness threshold.
 * It is resolved through the container (both constructor dependencies are
 * autowirable, so no hand-built arguments are needed); if that resolution
 * ever fails, the same public constants and cache key are read directly, so
 * the widget still reports something honest rather than dying.
 */
final readonly class SystemHealthViewModel
{
    public bool $schedulerHealthy;

    public ?Carbon $lastTickAt;

    public int $failedJobsCount;

    public ?Carbon $lastFailedAt;

    public int $queueDepth;

    public int $emailsQueueDepth;

    public ?CronTaskRun $lastRun;

    public ?string $lastFailedRunKey;

    public function __construct()
    {
        [$this->schedulerHealthy, $this->lastTickAt] = $this->resolveSchedulerHealth();
        [$this->failedJobsCount, $this->lastFailedAt] = $this->loadFailedJobs();
        $this->queueDepth = $this->countJobs(null);
        $this->emailsQueueDepth = $this->countJobs('emails');
        $this->lastRun = $this->loadLatestRun();
        $this->lastFailedRunKey = $this->loadLatestFailedRunKey();
    }

    /**
     * The scheduler heartbeat: whether `schedule:run` ticked inside
     * ScheduleInspector::STALE_AFTER_SECONDS, and when it last ticked.
     *
     * @return array{0: bool, 1: Carbon|null}
     */
    private function resolveSchedulerHealth(): array
    {
        try {
            $inspector = app(ScheduleInspector::class);

            return [$inspector->schedulerIsHealthy(), $inspector->lastTickAt()];
        } catch (Throwable) {
            return $this->heartbeatFromCache();
        }
    }

    /**
     * Direct read of the heartbeat cache key, for installs where the inspector
     * cannot be resolved. Uses ScheduleInspector's own constants so the two
     * paths can never disagree about the key or the threshold.
     *
     * @return array{0: bool, 1: Carbon|null}
     */
    private function heartbeatFromCache(): array
    {
        try {
            $stamp = Cache::get(ScheduleInspector::HEARTBEAT_KEY);
            $lastTick = $stamp === null ? null : Carbon::parse($stamp);

            return [
                $lastTick !== null
                    && $lastTick->diffInSeconds(Carbon::now(), absolute: true) <= ScheduleInspector::STALE_AFTER_SECONDS,
                $lastTick,
            ];
        } catch (Throwable) {
            return [false, null];
        }
    }

    /**
     * Failed jobs total and the timestamp of the most recent failure.
     *
     * @return array{0: int, 1: Carbon|null}
     */
    private function loadFailedJobs(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return [0, null];
            }

            $lastFailedAt = DB::table('failed_jobs')->max('failed_at');

            return [
                (int) DB::table('failed_jobs')->count(),
                $lastFailedAt === null ? null : Carbon::parse($lastFailedAt),
            ];
        } catch (Throwable) {
            return [0, null];
        }
    }

    /**
     * Queue depth. With $queue null it is every pending job; otherwise it is
     * the pending jobs on that single connection — the emails queue figure
     * `cron:emails-health` reports, kept identical to that command.
     */
    private function countJobs(?string $queue): int
    {
        try {
            if (! Schema::hasTable('jobs')) {
                return 0;
            }

            $query = DB::table('jobs');

            if ($queue !== null) {
                $query->where('queue', $queue);
            }

            return (int) $query->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The most recently started run of any scheduled task.
     */
    private function loadLatestRun(): ?CronTaskRun
    {
        try {
            if (! Schema::hasTable((new CronTaskRun)->getTable())) {
                return null;
            }

            return CronTaskRun::query()
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The task key of the most recent failed run, or null when no task has
     * failed. Used to explain a failed run row without exposing the payload.
     */
    private function loadLatestFailedRunKey(): ?string
    {
        try {
            if (! Schema::hasTable((new CronTaskRun)->getTable())) {
                return null;
            }

            $run = CronTaskRun::query()
                ->where('status', CronTaskRun::STATUS_FAILED)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->first();

            $key = $run?->task_key;

            return is_string($key) && trim($key) !== '' ? $key : null;
        } catch (Throwable) {
            return null;
        }
    }
}
