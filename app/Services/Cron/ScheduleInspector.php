<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\CronTaskRun;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Read model for the admin Cron Jobs page: turns the live
 * Illuminate\Console\Scheduling\Schedule into rows, decorated with the stored
 * enabled flag and the most recent recorded run.
 *
 * The schedule is the source of truth for WHAT exists and WHEN it runs — the
 * page never invents a task, it only reports and toggles what
 * routes/console.php declares.
 */
final class ScheduleInspector
{
    /** Cache key stamped by RecordScheduledTaskRun on every `schedule:run`. */
    public const HEARTBEAT_KEY = 'cron.scheduler.last_tick';

    /** Explicit health for tickets:fetch-mail — last successful IMAP poll. */
    public const TICKETS_HEALTH_KEY = 'cron.tickets.last_success';
    public const TICKETS_HEALTH_STATUS_KEY = 'cron.tickets.last_status';
    public const TICKETS_HEALTH_FAILED_AT_KEY = 'cron.tickets.last_failed_at';

    /**
     * Scheduler heartbeat staleness: schedule:run should fire every minute, so
     * 5 minutes is generous enough for load but short enough to catch a dead
     * Task Scheduler entry within minutes.
     */
    public const STALE_AFTER_SECONDS = 300;

    /**
     * Tickets fetch is everyFiveMinutes — allow 15 minutes (3 missed polls)
     * before considering the IMAP pipeline stale, even though the scheduler
     * itself may still be ticking.
     */
    public const TICKETS_STALE_AFTER_SECONDS = 900;

    public function __construct(
        private readonly Application $app,
        private readonly CronTaskRegistry $registry,
    ) {}

    /**
     * Every scheduled task as a display row.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function tasks(): Collection
    {
        $events = collect($this->schedule()->events());

        $keys = $events
            ->map(fn (Event $event): ?string => $this->registry->keyFor($event))
            ->filter()
            ->values()
            ->all();

        $enabled = $this->registry->enabledMap();
        $lastRuns = $this->lastRunsFor($keys);
        $stored = $this->storedOverrides($keys);

        // Ensure the display reflects DB overrides even when the schedule was
        // bootstrapped before the row was written in this process (e.g. a
        // test that inserts after bootstrap). The CLI path is already covered
        // in routes/console.php; this is the web fallback that makes tasks()
        // always authoritative.
        foreach ($events as $event) {
            $key = $this->registry->keyFor($event);
            if ($key === null || ! isset($stored[$key])) {
                continue;
            }
            $row = $stored[$key];
            if (is_string($row->expression) && trim($row->expression) !== '' && $event->getExpression() !== trim($row->expression)) {
                try {
                    $event->cron(trim($row->expression));
                } catch (Throwable) {
                }
            }
            if (is_string($row->timezone) && trim($row->timezone) !== '' && (string) ($event->timezone ?: config('app.timezone')) !== trim($row->timezone)) {
                try {
                    $event->timezone(trim($row->timezone));
                } catch (Throwable) {
                }
            }
            if (is_string($row->description) && trim($row->description) !== '') {
                $event->description(trim($row->description));
            }
        }

        return $events->map(function (Event $event) use ($enabled, $lastRuns, $stored): array {
            $key = $this->registry->keyFor($event);
            $row = $key !== null ? ($stored[$key] ?? null) : null;

            $isCustom = $row !== null
                && (is_string($row->expression) && trim($row->expression) !== ''
                    || is_string($row->timezone) && trim($row->timezone) !== ''
                    || is_string($row->description) && trim($row->description) !== '');

            return [
                'key' => $key,
                // An unnamed closure cannot be addressed across processes, so
                // it is listed for visibility but not offered a toggle.
                'manageable' => $key !== null,
                // NOT getSummaryForDisplay(): for a background task that
                // returns buildCommand(), i.e. the whole
                // `start /b cmd /v:on /c "..."` wrapper with absolute binary
                // paths — unreadable as a label.
                'name' => $event->description
                    ?: ($event instanceof CallbackEvent
                        ? 'Closure'
                        : $this->registry->commandSignature((string) $event->command)),
                'command' => $event instanceof CallbackEvent
                    ? 'Closure'
                    : $this->registry->commandSignature((string) $event->command),
                'expression' => $event->getExpression(),
                'human' => $this->humanize($event->getExpression()),
                'timezone' => (string) ($event->timezone ?: config('app.timezone')),
                'background' => (bool) $event->runInBackground,
                'without_overlapping' => (bool) $event->withoutOverlapping,
                'enabled' => $key === null || ($enabled[$key] ?? true),
                'next_due' => $this->nextDue($event),
                'last_run' => $key === null ? null : ($lastRuns[$key] ?? null),
                'is_custom' => $isCustom,
                'stored' => $row,
            ];
        })->values();
    }

    /**
     * The scheduled event behind a key, or null when the key no longer maps to
     * anything in console.php (a task that was renamed or removed).
     */
    public function findEvent(string $key): ?Event
    {
        foreach ($this->schedule()->events() as $event) {
            if ($this->registry->keyFor($event) === $key) {
                return $event;
            }
        }

        return null;
    }

    /** When `schedule:run` last completed, or null if it never has. */
    public function lastTickAt(): ?Carbon
    {
        try {
            $stamp = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            return null;
        }

        return $stamp === null ? null : Carbon::parse($stamp);
    }

    /**
     * False when the scheduler has never ticked or has not ticked recently —
     * the single most useful fact on the page, because every task silently
     * stops when the cron entry is missing.
     */
    public function schedulerIsHealthy(): bool
    {
        $lastTick = $this->lastTickAt();

        return $lastTick !== null
            && $lastTick->diffInSeconds(Carbon::now(), absolute: true) <= self::STALE_AFTER_SECONDS;
    }

    /** When tickets:fetch-mail last succeeded, or null if it never has. */
    public function ticketsLastSuccessAt(): ?Carbon
    {
        try {
            $stamp = Cache::get(self::TICKETS_HEALTH_KEY);
        } catch (Throwable) {
            return null;
        }

        return $stamp === null ? null : Carbon::parse($stamp);
    }

    public function ticketsLastFailedAt(): ?Carbon
    {
        try {
            $stamp = Cache::get(self::TICKETS_HEALTH_FAILED_AT_KEY);
        } catch (Throwable) {
            return null;
        }

        return $stamp === null ? null : Carbon::parse($stamp);
    }

    /**
     * True when tickets:fetch-mail has succeeded recently. Unlike
     * schedulerIsHealthy(), this catches a dead IMAP connection while the
     * scheduler is still ticking — the exact gap the heartbeat alone misses.
     * Returns null when there is no data yet (e.g. task disabled or never
     * run), so callers can distinguish "unknown" from "healthy/unhealthy".
     */
    public function ticketsMailIsHealthy(): ?bool
    {
        $lastSuccess = $this->ticketsLastSuccessAt();

        if ($lastSuccess === null) {
            // Fall back to CronTaskRun history for installs that haven't yet
            // populated the new cache key (or where cache was flushed).
            try {
                $lastRun = CronTaskRun::query()
                    ->where('task_key', 'tickets:fetch-mail')
                    ->where('status', CronTaskRun::STATUS_SUCCESS)
                    ->orderByDesc('id')
                    ->first();
                if ($lastRun !== null && $lastRun->finished_at !== null) {
                    $lastSuccess = Carbon::parse($lastRun->finished_at);
                }
            } catch (Throwable) {
            }

            if ($lastSuccess === null) {
                return null;
            }
        }

        return $lastSuccess->diffInSeconds(Carbon::now(), absolute: true) <= self::TICKETS_STALE_AFTER_SECONDS;
    }

    /**
     * routes/console.php is executed by the CONSOLE kernel's command
     * discovery. A web request never resolves that kernel, so without this
     * the Schedule singleton is empty and the page would list nothing.
     *
     * Kernel::bootstrap() is idempotent, and because the application is
     * already bootstrapped in an HTTP request it only performs the command /
     * console-route discovery step.
     */
    private function schedule(): Schedule
    {
        $this->app->make(ConsoleKernel::class)->bootstrap();

        return $this->app->make(Schedule::class);
    }

    /**
     * The most recent run per task key, in one query.
     *
     * @param  list<string>  $keys
     * @return array<string, CronTaskRun>
     */
    private function lastRunsFor(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        try {
            return CronTaskRun::query()
                ->whereIn('task_key', $keys)
                ->whereIn('id', fn ($query) => $query
                    ->selectRaw('MAX(id)')
                    ->from('cron_task_runs')
                    ->whereIn('task_key', $keys)
                    ->groupBy('task_key'))
                ->get()
                ->keyBy('task_key')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Stored row per key, if any — used to mark custom schedules and to
     * pre-fill the edit form without a second query per row.
     *
     * @param  list<string>  $keys
     * @return array<string, \App\Models\CronTask>
     */
    private function storedOverrides(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        try {
            return \App\Models\CronTask::query()
                ->whereIn('key', $keys)
                ->get()
                ->keyBy('key')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function nextDue(Event $event): ?Carbon
    {
        try {
            return Carbon::instance($event->nextRunDate());
        } catch (Throwable) {
            // Expressions constrained by ->between()/->when() can legitimately
            // have no next date; the row still renders without one.
            return null;
        }
    }

    /**
     * A plain-English reading of the common expressions this app schedules.
     * Anything unrecognised falls back to the raw expression rather than a
     * confident-sounding guess.
     */
    private function humanize(string $expression): string
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($fields) !== 5) {
            return $expression;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $daily = $dayOfMonth === '*' && $month === '*' && $dayOfWeek === '*';

        if ($daily && $minute === '*' && $hour === '*') {
            return 'Every minute';
        }

        if ($daily && $hour === '*' && preg_match('#^\*/(\d+)$#', $minute, $m) === 1) {
            return 'Every '.$m[1].' minutes';
        }

        if ($daily && $hour === '*' && ctype_digit($minute)) {
            return $minute === '0'
                ? 'Hourly'
                : 'Hourly at '.str_pad($minute, 2, '0', STR_PAD_LEFT).' minutes past';
        }

        if ($daily && preg_match('#^\*/(\d+)$#', $hour, $m) === 1 && ctype_digit($minute)) {
            return 'Every '.$m[1].' hours';
        }

        if ($daily && ctype_digit($hour) && ctype_digit($minute)) {
            return 'Daily at '.$this->clock($hour, $minute);
        }

        if ($dayOfMonth === '*' && $month === '*' && ctype_digit($dayOfWeek)
            && ctype_digit($hour) && ctype_digit($minute)) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            return 'Weekly on '.($days[(int) $dayOfWeek % 7]).' at '.$this->clock($hour, $minute);
        }

        if ($dayOfWeek === '*' && $month === '*' && ctype_digit($dayOfMonth)
            && ctype_digit($hour) && ctype_digit($minute)) {
            return 'Monthly on day '.$dayOfMonth.' at '.$this->clock($hour, $minute);
        }

        return $expression;
    }

    private function clock(string $hour, string $minute): string
    {
        return str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);
    }
}
