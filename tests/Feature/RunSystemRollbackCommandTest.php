<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The detached rollback command that the web UI launches.
 *
 * UpdateService is replaced with a recording fake so the command never touches
 * git, composer or the filesystem. The tests pin the two behaviours the UI
 * depends on: a terminal progress event reaches the same cache key the poller
 * reads, and a refused (busy) run leaves the in-flight run's progress alone.
 */
final class RunSystemRollbackCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A service that records the rollback call and, when an emitter is given,
     * publishes the terminal event the command is expected to relay.
     */
    private function recordingService(
        array $result,
        string $step = 'done',
        string $message = 'ok',
        int $progress = 100,
        bool $done = true,
        array $extra = ['status' => 'success'],
    ): UpdateService {
        return new class($result, $step, $message, $progress, $done, $extra) extends UpdateService
        {
            /** @var list<array{from: string, actor: User}> */
            public array $rollbackCalls = [];

            public function __construct(
                private readonly array $result,
                private readonly string $step,
                private readonly string $message,
                private readonly int $progress,
                private readonly bool $done,
                private readonly array $extra,
            ) {}

            public function rollback(string $fromHash, User $actor, ?callable $emit = null): array
            {
                $this->rollbackCalls[] = ['from' => $fromHash, 'actor' => $actor];

                if ($emit !== null) {
                    $emit($this->step, $this->message, $this->progress, $this->done, $this->extra);
                }

                return $this->result;
            }
        };
    }

    /** @return array<string, mixed> */
    private function successResult(): array
    {
        return ['status' => 'success', 'message' => 'ok', 'from' => 'aaaaaaa', 'to' => null, 'exit' => 0];
    }

    /** @return array<string, mixed> */
    private function busyResult(): array
    {
        return ['status' => 'busy', 'message' => 'busy', 'from' => 'bbbbbbb', 'to' => null, 'exit' => 1];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_command_runs_rollback_and_publishes_terminal_progress(): void
    {
        $user = User::factory()->create();

        $fake = $this->recordingService($this->successResult());
        $this->app->instance(UpdateService::class, $fake);

        $this->artisan('system:run-rollback', ['--actor' => $user->id, '--from' => 'aaaaaaa'])
            ->assertExitCode(0);

        $this->assertCount(1, $fake->rollbackCalls);
        $this->assertSame('aaaaaaa', $fake->rollbackCalls[0]['from']);
        $this->assertSame($user->id, $fake->rollbackCalls[0]['actor']->id);

        $progress = Cache::get('system.update_progress.'.$user->id);
        $this->assertIsArray($progress);
        $this->assertTrue($progress['done']);
        $this->assertSame('success', $progress['status']);
    }

    public function test_command_refuses_unknown_actor(): void
    {
        $fake = $this->recordingService($this->successResult());
        $this->app->instance(UpdateService::class, $fake);

        $this->artisan('system:run-rollback', ['--actor' => 999999, '--from' => 'aaaaaaa'])
            ->assertExitCode(1);

        $this->assertSame([], $fake->rollbackCalls);
    }

    public function test_command_does_not_clobber_another_runs_progress_on_busy(): void
    {
        $user = User::factory()->create();
        $cacheKey = 'system.update_progress.'.$user->id;

        $sentinel = ['step' => 'update', 'done' => false];
        Cache::put($cacheKey, $sentinel, 600);

        $fake = $this->recordingService(
            $this->busyResult(),
            step: 'error',
            message: 'busy',
            progress: 0,
            done: true,
            extra: ['status' => 'busy'],
        );
        $this->app->instance(UpdateService::class, $fake);

        $this->artisan('system:run-rollback', ['--actor' => $user->id, '--from' => 'aaaaaaa'])
            ->assertExitCode(0);

        $this->assertCount(1, $fake->rollbackCalls);

        // The run holding the lock owns this key; a refused run must not overwrite it.
        $this->assertSame($sentinel, Cache::get($cacheKey));
    }
}
