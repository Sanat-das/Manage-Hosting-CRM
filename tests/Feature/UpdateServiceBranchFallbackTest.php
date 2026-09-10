<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * run() must compare against, and pull from, the same upstream branch check()
 * reports on.
 *
 * check() has always fallen back to origin/master; run() queried origin/main
 * only and then hard-coded `git pull --ff-only origin main`. On a master-default
 * checkout that meant the page offered an update and the button answered
 * "Already up to date — no commits to pull" with exit 0.
 *
 * Every git call is stubbed, so no repository, remote or network is touched.
 */
final class UpdateServiceBranchFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A service whose git/artisan calls answer from $responder.
     *
     * Returning null from the responder means "succeeded with no output".
     */
    private function gitService(callable $responder): UpdateService
    {
        return new class($responder) extends UpdateService
        {
            /** @var list<string> */
            public array $commands = [];

            /** @var callable */
            private $responder;

            public function __construct(callable $responder)
            {
                $this->responder = $responder;
            }

            protected function composerAvailable(): bool
            {
                return false;
            }

            protected function runProcess(array $cmd, int $timeout = 3): array
            {
                $line = implode(' ', $cmd);
                $this->commands[] = $line;

                return ($this->responder)($line) ?? ['output' => '', 'exit' => 0, 'success' => true];
            }
        };
    }

    private function procOk(string $output = ''): array
    {
        return ['output' => $output, 'exit' => 0, 'success' => true];
    }

    private function procFail(string $output = ''): array
    {
        return ['output' => $output, 'exit' => 1, 'success' => false];
    }

    /**
     * A git checkout where only the named upstream branches exist.
     *
     * @param  list<string>  $existingRefs
     */
    private function responderFor(array $existingRefs, int $behind = 3): callable
    {
        return function (string $cmd) use ($existingRefs, $behind): ?array {
            // Order matters: the more specific rev-parse forms are matched first.
            if (str_contains($cmd, 'rev-parse --is-inside-work-tree')) {
                return $this->procOk('true');
            }

            if (str_contains($cmd, 'rev-parse --verify --quiet ')) {
                foreach ($existingRefs as $ref) {
                    if (str_ends_with($cmd, $ref)) {
                        return $this->procOk(str_repeat('b', 40));
                    }
                }

                return $this->procFail();
            }

            if (str_contains($cmd, 'rev-parse --abbrev-ref HEAD')) {
                return $this->procOk($existingRefs === [] ? 'main' : $this->branchOf($existingRefs[0]));
            }

            if (str_contains($cmd, 'rev-parse HEAD')) {
                return $this->procOk(str_repeat('a', 40));
            }

            if (str_contains($cmd, 'remote get-url origin')) {
                return $this->procOk('https://github.com/Sanat-das/Manage-Hosting-CRM.git');
            }

            if (str_contains($cmd, 'rev-list HEAD..')) {
                foreach ($existingRefs as $ref) {
                    if (str_contains($cmd, 'HEAD..' . $ref . ' ')) {
                        return $this->procOk((string) $behind);
                    }
                }

                return $this->procFail('unknown revision');
            }

            // Clean tree, successful fetch/pull/artisan.
            return null;
        };
    }

    private function branchOf(string $ref): string
    {
        return substr($ref, (int) strrpos($ref, '/') + 1);
    }

    private function invokePrivate(UpdateService $service, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }

    // ------------------------------------------------------------------
    // Ref resolution
    // ------------------------------------------------------------------

    public function test_origin_main_is_preferred_when_both_branches_exist(): void
    {
        $service = $this->gitService($this->responderFor(['origin/main', 'origin/master']));

        $this->assertSame('origin/main', $this->invokePrivate($service, 'resolveUpstreamRef'));
    }

    public function test_origin_master_is_used_when_origin_main_is_absent(): void
    {
        $service = $this->gitService($this->responderFor(['origin/master']));

        $this->assertSame('origin/master', $this->invokePrivate($service, 'resolveUpstreamRef'));
    }

    public function test_no_upstream_ref_resolves_to_null(): void
    {
        $service = $this->gitService($this->responderFor([]));

        $this->assertNull($this->invokePrivate($service, 'resolveUpstreamRef'));
    }

    public function test_branch_name_is_taken_from_the_ref(): void
    {
        $service = $this->gitService($this->responderFor(['origin/main']));

        $this->assertSame('master', $this->invokePrivate($service, 'upstreamBranchName', ['origin/master']));
        $this->assertSame('main', $this->invokePrivate($service, 'upstreamBranchName', ['origin/main']));
        $this->assertSame('main', $this->invokePrivate($service, 'upstreamBranchName', ['main']));
    }

    // ------------------------------------------------------------------
    // run() on a master-default checkout
    // ------------------------------------------------------------------

    public function test_a_master_only_checkout_counts_commits_and_pulls_from_master(): void
    {
        $service = $this->gitService($this->responderFor(['origin/master'], behind: 3));

        $result = $service->run(User::factory()->create());

        // Before the fallback this returned up_to_date with behind 0 — a silent
        // no-op, while the page was advertising three pending commits.
        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['behind']);

        $this->assertContains('git rev-list HEAD..origin/master --count', $service->commands);
        $this->assertContains('git pull --ff-only origin master', $service->commands);
        $this->assertNotContains('git pull --ff-only origin main', $service->commands);
    }

    public function test_a_main_checkout_still_pulls_from_main(): void
    {
        $service = $this->gitService($this->responderFor(['origin/main'], behind: 2));

        $result = $service->run(User::factory()->create());

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['behind']);

        $this->assertContains('git pull --ff-only origin main', $service->commands);
        $this->assertNotContains('git pull --ff-only origin master', $service->commands);
    }

    public function test_the_same_ref_drives_the_count_and_the_pull(): void
    {
        $service = $this->gitService($this->responderFor(['origin/master'], behind: 1));

        $service->run(User::factory()->create());

        $counted = array_values(array_filter(
            $service->commands,
            static fn (string $c): bool => str_starts_with($c, 'git rev-list HEAD..')
        ));
        $pulled = array_values(array_filter(
            $service->commands,
            static fn (string $c): bool => str_starts_with($c, 'git pull ')
        ));

        $this->assertCount(1, $pulled);
        $this->assertNotSame([], $counted);

        // Comparing against one branch and pulling from another is the bug class
        // this test exists to prevent, so assert they agree rather than assert
        // two hard-coded strings.
        $this->assertStringEndsWith(
            $this->branchOf(str_replace([' --count', 'git rev-list HEAD..'], '', $counted[0])),
            $pulled[0]
        );
    }

    public function test_a_missing_upstream_branch_fails_loudly_instead_of_reporting_up_to_date(): void
    {
        $service = $this->gitService($this->responderFor([]));

        $result = $service->run(User::factory()->create());

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('origin/main or origin/master', $result['message']);

        // Nothing was mutated: no maintenance mode, no pull.
        $this->assertSame([], array_filter(
            $service->commands,
            static fn (string $c): bool => str_contains($c, 'artisan down') || str_starts_with($c, 'git pull')
        ));
    }
}
