<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Listeners\RecordScheduledTaskRun;
use App\Models\CronTask;
use App\Models\CronTaskRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Cron\CronTaskRegistry;
use App\Services\Cron\ScheduleInspector;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

/**
 * Admin Cron Jobs page: the task list read off the live Schedule, the
 * enable/disable gate that ScheduleRunCommand honours, manual runs, the run
 * history written from Laravel's scheduling events, and the scheduler
 * heartbeat that catches a missing OS-level cron entry.
 */
final class CronJobsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tasks registered by the test itself, so "run now" never has to execute
     * a real business command (and its side effects) to prove the mechanism.
     */
    private const TEST_TASK_KEY = 'cron-test-task';

    protected function setUp(): void
    {
        parent::setUp();

        // The open-run map is process-lifetime state; RefreshDatabase swaps the
        // database underneath it between cases.
        RecordScheduledTaskRun::reset();
    }

    // ------------------------------------------------------------------
    // Task identity — the contract between the web page and the CLI.
    // ------------------------------------------------------------------

    public function test_command_key_is_identical_across_sapis(): void
    {
        $registry = app(CronTaskRegistry::class);

        // The web SAPI resolves php-cgi.exe while the CLI resolves php.exe. If
        // the binary leaked into the key, the page and `schedule:run` would
        // disagree about which task an operator just disabled.
        $fromCgi = $registry->commandSignature(
            '"C:\php-8.4\bin\php-cgi.exe" "artisan" app:cleanup --days=90'
        );
        $fromCli = $registry->commandSignature(
            ConsoleApplication::formatCommandString('app:cleanup --days=90')
        );

        $this->assertSame('app:cleanup --days=90', $fromCgi);
        $this->assertSame($fromCgi, $fromCli);
    }

    public function test_named_closure_tasks_are_manageable_and_unnamed_ones_are_not(): void
    {
        $schedule = app(Schedule::class);
        $registry = app(CronTaskRegistry::class);

        $named = $schedule->call(fn () => null)->name('cron-test-named');
        $unnamed = $schedule->call(fn () => null);

        $this->assertSame('cron-test-named', $registry->keyFor($named));
        $this->assertNull(
            $registry->keyFor($unnamed),
            'An unnamed closure has no identity that survives across processes.'
        );
    }

    // ------------------------------------------------------------------
    // The page itself.
    // ------------------------------------------------------------------

    public function test_index_lists_the_tasks_declared_in_console_routes(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.cron.index'));

        $response->assertOk();
        $response->assertSee('billing:recurring');
        $response->assertSee('snmp-poll-dispatch-due');
        // The humanised expression, not just the raw cron string.
        $response->assertSee('Daily at 01:00');
    }

    public function test_background_tasks_are_labelled_by_signature_not_by_the_shell_wrapper(): void
    {
        $task = app(ScheduleInspector::class)->tasks()->firstWhere('key', 'ssh:prune');

        $this->assertNotNull($task);
        $this->assertTrue($task['background'], 'Fixture assumes ssh:prune runs in background.');
        // Event::getSummaryForDisplay() would return buildCommand() here — the
        // whole `start /b cmd /v:on /c "..."` wrapper with absolute PHP paths.
        $this->assertSame('ssh:prune', $task['name']);

        $this->actingAsAdmin()->get(route('admin.cron.index'))
            ->assertSee('ssh:prune')
            ->assertDontSee('start /b cmd');
    }

    public function test_index_warns_when_the_scheduler_has_never_ticked(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.cron.index'));

        $response->assertOk();
        $response->assertSee('The scheduler is not running');
        $response->assertSee('has never completed on this installation', false);
    }

    public function test_index_reports_a_healthy_scheduler_after_a_recent_tick(): void
    {
        Cache::forever(ScheduleInspector::HEARTBEAT_KEY, Carbon::now()->subMinute()->toIso8601String());

        $response = $this->actingAsAdmin()->get(route('admin.cron.index'));

        $response->assertOk();
        $response->assertSee('Scheduler is ticking');
        $response->assertDontSee('The scheduler is not running');
    }

    public function test_a_stale_heartbeat_is_reported_as_unhealthy(): void
    {
        Cache::forever(
            ScheduleInspector::HEARTBEAT_KEY,
            Carbon::now()->subSeconds(ScheduleInspector::STALE_AFTER_SECONDS + 60)->toIso8601String(),
        );

        $this->assertFalse(app(ScheduleInspector::class)->schedulerIsHealthy());

        $this->actingAsAdmin()->get(route('admin.cron.index'))
            ->assertSee('The scheduler is not running');
    }

    public function test_guest_is_redirected_and_cron_view_is_required(): void
    {
        $this->get(route('admin.cron.index'))->assertRedirect();

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('admin.cron.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Enable / disable — the gate ScheduleRunCommand actually honours.
    // ------------------------------------------------------------------

    public function test_disabling_a_task_makes_the_scheduler_filter_it_out(): void
    {
        $event = $this->scheduleEventFor('snmp-poll-dispatch-due');

        $this->assertTrue($event->filtersPass(app()), 'A task with no stored row must run.');

        app(CronTaskRegistry::class)->setEnabled('snmp-poll-dispatch-due', false);

        $this->assertFalse(
            $event->filtersPass(app()),
            'ScheduleRunCommand skips a due event whose filters fail — that is the whole gate.'
        );
    }

    public function test_toggle_route_persists_the_flag(): void
    {
        $this->actingAsAdmin()->post(route('admin.cron.toggle'), [
            'key' => 'billing:recurring',
            'enabled' => 0,
        ])->assertRedirect();

        $this->assertFalse(CronTask::query()->findOrFail('billing:recurring')->enabled);
        $this->assertFalse(app(CronTaskRegistry::class)->isEnabled('billing:recurring'));

        $this->actingAsAdmin()->post(route('admin.cron.toggle'), [
            'key' => 'billing:recurring',
            'enabled' => 1,
        ])->assertRedirect();

        $this->assertTrue(app(CronTaskRegistry::class)->isEnabled('billing:recurring'));
    }

    public function test_toggle_rejects_a_key_that_matches_no_scheduled_task(): void
    {
        $this->actingAsAdmin()->post(route('admin.cron.toggle'), [
            'key' => 'not:a-real-task',
            'enabled' => 0,
        ])->assertSessionHasErrors('key');

        $this->assertDatabaseCount('cron_tasks', 0);
    }

    public function test_toggle_requires_the_manage_permission(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->post(route('admin.cron.toggle'), ['key' => 'billing:recurring', 'enabled' => 0])
            ->assertForbidden();

        $this->assertDatabaseCount('cron_tasks', 0);
    }

    // ------------------------------------------------------------------
    // Manual run.
    // ------------------------------------------------------------------

    public function test_run_now_executes_a_closure_task_and_records_a_manual_run(): void
    {
        $ran = false;
        $this->registerTestTask(function () use (&$ran): void {
            $ran = true;
        });

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.cron.run'), ['key' => self::TEST_TASK_KEY])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue($ran, 'Run now must invoke the task in-request, not queue it.');

        $run = CronTaskRun::query()->where('task_key', self::TEST_TASK_KEY)->sole();

        $this->assertSame(CronTaskRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(CronTaskRun::TRIGGER_MANUAL, $run->trigger);
        $this->assertSame($admin->id, $run->triggered_by);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->runtime_ms);
    }

    public function test_run_now_executes_a_command_task_and_captures_its_output(): void
    {
        app(Schedule::class)->command('inspire')->daily();

        $this->actingAsAdmin()
            ->post(route('admin.cron.run'), ['key' => 'inspire'])
            ->assertSessionHas('success');

        $run = CronTaskRun::query()->where('task_key', 'inspire')->sole();

        $this->assertSame(CronTaskRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertNotNull($run->message, 'Command stdout is captured onto the run row.');
    }

    public function test_run_now_records_a_failure_without_letting_it_escape(): void
    {
        $this->registerTestTask(fn () => throw new RuntimeException('task exploded'));

        $this->actingAsAdmin()
            ->post(route('admin.cron.run'), ['key' => self::TEST_TASK_KEY])
            ->assertSessionHasErrors('key');

        $run = CronTaskRun::query()->where('task_key', self::TEST_TASK_KEY)->sole();

        $this->assertSame(CronTaskRun::STATUS_FAILED, $run->status);
        $this->assertSame('task exploded', $run->message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_run_now_works_on_a_disabled_task(): void
    {
        // A disabled task is out of the automatic rotation, not broken — the
        // manual button is the escape hatch for exactly that state.
        $this->registerTestTask(fn () => null);
        app(CronTaskRegistry::class)->setEnabled(self::TEST_TASK_KEY, false);

        $this->actingAsAdmin()
            ->post(route('admin.cron.run'), ['key' => self::TEST_TASK_KEY])
            ->assertSessionHas('success');

        $this->assertSame(
            CronTaskRun::STATUS_SUCCESS,
            CronTaskRun::query()->where('task_key', self::TEST_TASK_KEY)->sole()->status,
        );
    }

    public function test_run_now_rejects_an_unknown_key(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.cron.run'), ['key' => 'not:a-real-task'])
            ->assertSessionHasErrors('key');

        $this->assertDatabaseCount('cron_task_runs', 0);
    }

    // ------------------------------------------------------------------
    // Run history written from Laravel's scheduling events.
    // ------------------------------------------------------------------

    public function test_a_successful_scheduled_run_is_recorded(): void
    {
        // A foreground task: ScheduledTaskFinished carries the real result.
        $event = $this->scheduleEventFor('snmp:maintain-partitions');
        $event->exitCode = 0;

        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskFinished($event, 1.5));

        $run = CronTaskRun::query()->sole();

        $this->assertSame('snmp:maintain-partitions', $run->task_key);
        $this->assertSame(CronTaskRun::STATUS_SUCCESS, $run->status);
        $this->assertSame(CronTaskRun::TRIGGER_SCHEDULE, $run->trigger);
        $this->assertSame(1500, $run->runtime_ms);
        $this->assertSame(0, $run->exit_code);
    }

    public function test_a_nonzero_exit_code_is_recorded_as_failed_with_the_exception_message(): void
    {
        $event = $this->scheduleEventFor('snmp:maintain-partitions');

        event(new ScheduledTaskStarting($event));

        // ScheduleRunCommand dispatches Finished first and only then Failed,
        // so both must land on the SAME row rather than opening a second one.
        $event->exitCode = 1;
        event(new ScheduledTaskFinished($event, 0.25));
        event(new ScheduledTaskFailed($event, new RuntimeException('billing blew up')));

        $run = CronTaskRun::query()->sole();

        $this->assertSame(CronTaskRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->exit_code);
        $this->assertSame('billing blew up', $run->message);
    }

    public function test_a_background_task_is_not_closed_until_the_finish_process_reports(): void
    {
        $event = $this->scheduleEventFor('ssh:prune');
        $this->assertTrue((bool) $event->runInBackground, 'Fixture assumes ssh:prune runs in background.');

        event(new ScheduledTaskStarting($event));
        // Fires immediately after SPAWNING the process — the work has not run.
        event(new ScheduledTaskFinished($event, 0.01));

        $this->assertSame(
            CronTaskRun::STATUS_RUNNING,
            CronTaskRun::query()->sole()->status,
            'Closing here would record a success before the background process ran.'
        );
    }

    public function test_the_index_shows_the_recorded_history(): void
    {
        CronTaskRun::query()->create([
            'task_key' => 'billing:recurring',
            'status' => CronTaskRun::STATUS_FAILED,
            'trigger' => CronTaskRun::TRIGGER_SCHEDULE,
            'started_at' => Carbon::now()->subMinutes(5),
            'finished_at' => Carbon::now()->subMinutes(5),
            'exit_code' => 1,
            'message' => 'a recorded failure',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.cron.index'));

        $response->assertOk();
        $response->assertSee('a recorded failure');
        $response->assertSee('Failed');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Add a named closure task to the live schedule. The test and the request
     * under test share one application instance, so the controller resolves
     * the same Schedule and finds it.
     */
    private function registerTestTask(callable $callback): void
    {
        app(Schedule::class)->call($callback)->name(self::TEST_TASK_KEY)->everyMinute();
    }

    private function scheduleEventFor(string $key): ScheduledEvent
    {
        $event = app(ScheduleInspector::class)->findEvent($key);

        $this->assertNotNull($event, "routes/console.php no longer declares [{$key}].");

        return $event;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function adminUser(array $permissions = ['cron.view', 'cron.manage']): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsAdmin(array $permissions = ['cron.view', 'cron.manage']): self
    {
        return $this->actingAs($this->adminUser($permissions));
    }
}
