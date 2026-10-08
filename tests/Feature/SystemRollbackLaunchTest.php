<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\SystemController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\System\AppInfoService;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The rollback endpoint's background-launch branch.
 *
 * The real launchDetached() spawns a detached PowerShell process, which no test
 * may do, so the controller is bound to a subclass that records the launch call
 * and returns a chosen result. The service is a recording fake, so the tests can
 * tell "the detached branch ran" (service untouched) from "it fell through to
 * the synchronous path" (service called).
 */
final class SystemRollbackLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The progress key and the throttle store both live in the array cache,
        // which RefreshDatabase does not roll back between tests.
        Cache::flush();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function adminUser(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $admin->roles()->syncWithoutDetaching($role);

        $perm = Permission::firstOrCreate(['name' => 'system.update'], ['label' => 'Perform System Update']);
        $role->permissions()->syncWithoutDetaching($perm);

        return $admin;
    }

    /** @return array<string, mixed> */
    private function cannedResult(): array
    {
        return [
            'status' => 'success',
            'message' => 'canned',
            'from' => 'aaaaaaa',
            'to' => null,
            'branch' => 'main',
            'remoteSanitized' => null,
            'exit' => 0,
            'durationMs' => 1,
            'output' => '',
        ];
    }

    /**
     * Bind the controller with a stubbed launchDetached() and a recording
     * UpdateService, returning both so a test can inspect what was called.
     *
     * @return array{0: object, 1: object}
     */
    private function bindController(bool $launchResult): array
    {
        $result = $this->cannedResult();

        $fake = new class($result) extends UpdateService
        {
            /** @var list<array{from: string, actor: User}> */
            public array $rollbackCalls = [];

            public function __construct(private readonly array $result) {}

            public function rollback(string $fromHash, User $actor, ?callable $emit = null): array
            {
                $this->rollbackCalls[] = ['from' => $fromHash, 'actor' => $actor];

                if ($emit !== null) {
                    $emit('done', 'canned', 100, true, ['status' => 'success']);
                }

                return $this->result;
            }
        };

        $controller = new class(new AppInfoService, $fake, $launchResult) extends SystemController
        {
            /** @var list<array{command: string, launcher: string, log: string}> */
            public array $launchCalls = [];

            public function __construct(AppInfoService $info, UpdateService $updater, private readonly bool $launchResult)
            {
                parent::__construct($info, $updater);
            }

            protected function launchDetached(string $artisanCommand, string $launcherName, string $bgLog): bool
            {
                $this->launchCalls[] = ['command' => $artisanCommand, 'launcher' => $launcherName, 'log' => $bgLog];

                return $this->launchResult;
            }
        };

        $this->app->bind(SystemController::class, fn () => $controller);

        return [$controller, $fake];
    }

    /**
     * Bind the controller with a launchDetached() that throws, plus a recording
     * UpdateService, to prove a failed launch can never 500 the request.
     *
     * @return array{0: object, 1: object}
     */
    private function bindThrowingController(): array
    {
        $result = $this->cannedResult();

        $fake = new class($result) extends UpdateService
        {
            /** @var list<array{from: string, actor: User}> */
            public array $rollbackCalls = [];

            public function __construct(private readonly array $result) {}

            public function rollback(string $fromHash, User $actor, ?callable $emit = null): array
            {
                $this->rollbackCalls[] = ['from' => $fromHash, 'actor' => $actor];

                if ($emit !== null) {
                    $emit('done', 'canned', 100, true, ['status' => 'success']);
                }

                return $this->result;
            }
        };

        $controller = new class(new AppInfoService, $fake) extends SystemController
        {
            public function __construct(AppInfoService $info, UpdateService $updater)
            {
                parent::__construct($info, $updater);
            }

            protected function launchDetached(string $artisanCommand, string $launcherName, string $bgLog): bool
            {
                throw new \RuntimeException('detached launch exploded');
            }
        };

        $this->app->bind(SystemController::class, fn () => $controller);

        return [$controller, $fake];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_ajax_rollback_launches_detached_and_returns_started(): void
    {
        [$controller, $fake] = $this->bindController(true);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson(route('admin.system.rollback'), ['from_hash' => 'aaaaaaa']);

        $response->assertOk();
        $response->assertJsonPath('status', 'started');

        // The detached branch answers immediately; the service must not run.
        $this->assertSame([], $fake->rollbackCalls);
        $this->assertCount(1, $controller->launchCalls);

        $progress = Cache::get('system.update_progress.'.$admin->id);
        $this->assertIsArray($progress);
        $this->assertFalse($progress['done']);
        $this->assertSame('waiting', $progress['step']);
    }

    public function test_ajax_rollback_falls_back_to_synchronous_when_launch_fails(): void
    {
        [$controller, $fake] = $this->bindController(false);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson(route('admin.system.rollback'), ['from_hash' => 'aaaaaaa']);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('message', 'canned');

        $this->assertCount(1, $controller->launchCalls);
        $this->assertCount(1, $fake->rollbackCalls);
        $this->assertSame('aaaaaaa', $fake->rollbackCalls[0]['from']);
    }

    public function test_rollback_target_with_shell_metacharacters_never_launches(): void
    {
        [$controller, $fake] = $this->bindController(true);
        $admin = $this->adminUser();

        $raw = 'aaaaaaa & whoami';

        $response = $this->actingAs($admin)->postJson(route('admin.system.rollback'), ['from_hash' => $raw]);

        // A target that is not a plain token must never reach the .cmd launcher;
        // the service validates it on the synchronous path instead.
        $this->assertSame([], $controller->launchCalls);
        $this->assertCount(1, $fake->rollbackCalls);
        $this->assertSame($raw, $fake->rollbackCalls[0]['from']);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('message', 'canned');
    }

    public function test_form_rollback_redirects_on_success(): void
    {
        [$controller, $fake] = $this->bindController(true);
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.system.rollback'), ['from_hash' => 'aaaaaaa']);

        $response->assertRedirect(route('admin.system.index', ['tab' => 'updates']));
        $response->assertSessionHas('success', 'canned');

        // No JSON Accept header, so the detached branch is never considered.
        $this->assertSame([], $controller->launchCalls);
        $this->assertCount(1, $fake->rollbackCalls);
    }

    public function test_posix_launch_command_detaches_with_escaped_arguments(): void
    {
        $controller = new class extends SystemController
        {
            public function __construct() {}
        };

        $method = new ReflectionMethod($controller, 'posixLaunchCommand');
        $method->setAccessible(true);

        $log = storage_path('logs/rollback-bg.log');
        $cmd = $method->invoke($controller, "--from=we'ird", $log);

        $this->assertStringStartsWith('nohup ', $cmd);
        $this->assertStringContainsString('setsid -f', $cmd);
        $this->assertStringEndsWith(' &', $cmd);

        // Paths are single-quoted, and a single quote inside a token is escaped
        // as '\'' so it cannot break out of its argument.
        $this->assertStringContainsString("'".base_path('artisan')."'", $cmd);
        $this->assertStringContainsString("'".$log."'", $cmd);
        $this->assertStringContainsString("'\''", $cmd);
    }

    public function test_rollback_launch_throw_falls_back_to_synchronous_without_500(): void
    {
        [, $fake] = $this->bindThrowingController();
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson(route('admin.system.rollback'), ['from_hash' => 'aaaaaaa']);

        // A throwing launch must be caught and fall through to the synchronous
        // path, not surface as a 500.
        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('message', 'canned');

        $this->assertCount(1, $fake->rollbackCalls);
        $this->assertSame('aaaaaaa', $fake->rollbackCalls[0]['from']);
    }
}
