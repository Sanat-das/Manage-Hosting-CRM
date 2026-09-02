<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CronTaskRun;
use App\Services\Cron\CronTaskRegistry;
use App\Services\Cron\ScheduleInspector;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Admin Cron Jobs page — read and operate the tasks declared in
 * routes/console.php.
 *
 * The page does NOT define schedules. It reports what the code declares,
 * toggles individual tasks off, exposes Laravel's global pause switch, and
 * runs a task on demand. The most load-bearing thing it shows is the
 * scheduler heartbeat: when the OS-level `schedule:run` entry is missing,
 * every task silently stops and nothing else in the app says so.
 */
class CronJobController extends Controller
{
    /** Truncation applied to captured `run now` output before it is stored. */
    private const OUTPUT_LIMIT = 20000;

    public function __construct(
        private readonly ScheduleInspector $inspector,
        private readonly CronTaskRegistry $registry,
    ) {}

    public function index(Request $request): View
    {
        $tasks = $this->inspector->tasks();

        $runs = CronTaskRun::query()
            ->when($request->filled('task'), fn ($query) => $query->where('task_key', $request->query('task')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.cron.index', [
            'tasks' => $tasks,
            'runs' => $runs,
            'lastTickAt' => $this->inspector->lastTickAt(),
            'schedulerHealthy' => $this->inspector->schedulerIsHealthy(),
            'staleAfter' => ScheduleInspector::STALE_AFTER_SECONDS,
            'paused' => (bool) Cache::get('illuminate:schedule:paused', false),
        ]);
    }

    /**
     * Take one task in or out of rotation. The schedule stays declared in
     * code; this only records the deviation.
     */
    public function toggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'enabled' => ['required', 'boolean'],
        ]);

        if ($this->inspector->findEvent($validated['key']) === null) {
            return back()->withErrors(['key' => "No scheduled task matches [{$validated['key']}]."]);
        }

        $this->registry->setEnabled(
            $validated['key'],
            (bool) $validated['enabled'],
            $request->user()?->id,
        );

        return back()->with('success', sprintf(
            '%s has been %s.',
            $validated['key'],
            $validated['enabled'] ? 'enabled' : 'disabled',
        ));
    }

    /**
     * Edit the timing / metadata of a scheduled task.
     *
     * The schedule is still declared in routes/console.php — this stores the
     * override that routes/console.php applies on top of the code default. An
     * empty expression / timezone / description means "use the code default".
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
            'expression' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $key = $validated['key'];

        if ($this->inspector->findEvent($key) === null) {
            return back()->withErrors(['key' => "No scheduled task matches [{$key}]."]);
        }

        $expression = isset($validated['expression']) ? trim((string) $validated['expression']) : null;
        $expression = $expression === '' ? null : $expression;

        if ($expression !== null && ! $this->isValidCronExpression($expression)) {
            return back()->withErrors(['expression' => 'The schedule is not a valid 5-field cron expression.'])->withInput();
        }

        $timezone = isset($validated['timezone']) ? trim((string) $validated['timezone']) : null;
        $timezone = $timezone === '' ? null : $timezone;

        if ($timezone !== null && ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return back()->withErrors(['timezone' => 'Unknown timezone.'])->withInput();
        }

        $description = isset($validated['description']) ? trim((string) $validated['description']) : null;
        $description = $description === '' ? null : $description;

        $attributes = [
            'expression' => $expression,
            'timezone' => $timezone,
            'description' => $description,
            'updated_by' => $request->user()?->id,
        ];

        if (array_key_exists('enabled', $validated)) {
            $attributes['enabled'] = (bool) $validated['enabled'];
        }

        \App\Models\CronTask::query()->updateOrCreate(
            ['key' => $key],
            $attributes,
        );

        return back()->with('success', sprintf('%s schedule updated.', $key));
    }

    private function isValidCronExpression(string $expression): bool
    {
        // Prefer the same parser Laravel uses; fall back to a minimal shape check.
        if (class_exists(\Cron\CronExpression::class)) {
            return \Cron\CronExpression::isValidExpression($expression);
        }

        return preg_match('/^(\S+\s+){4}\S+$/', $expression) === 1;
    }

    /**
     * Run one task immediately, in this request.
     *
     * Deliberately synchronous: the queue worker is itself one of the things
     * that may be down when an admin reaches for this button, so dispatching
     * the work would be the least reliable option available. Long commands
     * will hold the request open — that is visible and preferable to a
     * "queued" message that never resolves.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'max:191'],
        ]);

        $key = $validated['key'];
        $event = $this->inspector->findEvent($key);

        if ($event === null) {
            return back()->withErrors(['key' => "No scheduled task matches [{$key}]."]);
        }

        $run = CronTaskRun::query()->create([
            'task_key' => $key,
            'status' => CronTaskRun::STATUS_RUNNING,
            'trigger' => CronTaskRun::TRIGGER_MANUAL,
            'started_at' => Carbon::now(),
            'triggered_by' => $request->user()?->id,
        ]);

        $startedAt = microtime(true);

        try {
            [$exitCode, $output] = $event instanceof CallbackEvent
                ? [$this->runCallback($event), '']
                : $this->runCommand($key);

            $run->forceFill([
                'status' => $exitCode === 0 ? CronTaskRun::STATUS_SUCCESS : CronTaskRun::STATUS_FAILED,
                'exit_code' => $exitCode,
                'finished_at' => Carbon::now(),
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'message' => Str::limit(trim($output), self::OUTPUT_LIMIT) ?: null,
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => CronTaskRun::STATUS_FAILED,
                'finished_at' => Carbon::now(),
                'runtime_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'message' => $e->getMessage(),
            ])->save();

            return back()->withErrors(['key' => "{$key} failed: {$e->getMessage()}"]);
        }

        return $run->status === CronTaskRun::STATUS_SUCCESS
            ? back()->with('success', "{$key} ran successfully.")
            : back()->withErrors(['key' => "{$key} exited with code {$run->exit_code}."]);
    }

    /**
     * Laravel's own global pause switch (`schedule:pause` / `schedule:resume`).
     * Unlike the per-task toggle this stops EVERY task at once, which is what
     * you want during a migration or an incident.
     */
    public function pause(Request $request): RedirectResponse
    {
        if (! Schedule::$pausable) {
            return back()->withErrors(['pause' => 'Schedule pausing is disabled in this application.']);
        }

        $resume = $request->boolean('resume');

        Artisan::call($resume ? 'schedule:resume' : 'schedule:pause');

        return back()->with('success', $resume
            ? 'Scheduler resumed — tasks will run at their next due time.'
            : 'Scheduler paused — no task will run until it is resumed.');
    }

    /**
     * A callback task returns no exit code; reaching the end without throwing
     * is the success signal.
     */
    private function runCallback(CallbackEvent $event): int
    {
        $event->run(app());

        return 0;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCommand(string $signature): array
    {
        $exitCode = Artisan::call($signature);

        return [$exitCode, Artisan::output()];
    }
}
