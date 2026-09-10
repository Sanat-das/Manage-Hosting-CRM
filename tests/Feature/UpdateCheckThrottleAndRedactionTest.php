<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\UpdateService;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Two things check() must not do on every render of the About page: hit the
 * network, and write credentials into the logs.
 *
 * The fetch is the only step in check() that needs the network — measured at
 * ~850 ms warm on this host, but blocking for the full 15 s timeout whenever
 * GitHub is unreachable. Everything else is local git state and stays live.
 */
final class UpdateCheckThrottleAndRedactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * A service whose git calls are answered in-memory.
     *
     * Note this stubs runProcess(), which sits *above* runRaw() — so redaction
     * is deliberately not exercised here. It is covered directly further down.
     */
    private function gitService(bool $fetchSucceeds = true): UpdateService
    {
        return new class($fetchSucceeds) extends UpdateService
        {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(private readonly bool $fetchSucceeds) {}

            protected function runProcess(array $cmd, int $timeout = 3): array
            {
                $line = implode(' ', $cmd);
                $this->commands[] = $line;

                $ok = static fn (string $out = ''): array => ['output' => $out, 'exit' => 0, 'success' => true];

                if (str_contains($line, 'rev-parse --is-inside-work-tree')) {
                    return $ok('true');
                }

                if (str_contains($line, 'remote get-url origin')) {
                    return $ok('https://github.com/Sanat-das/Manage-Hosting-CRM.git');
                }

                if (str_contains($line, 'git fetch origin')) {
                    return $this->fetchSucceeds
                        ? $ok()
                        : ['output' => 'fatal: unable to access — could not resolve host', 'exit' => 128, 'success' => false];
                }

                if (str_contains($line, 'rev-list HEAD..')) {
                    return $ok('0');
                }

                if (str_contains($line, 'rev-parse --abbrev-ref HEAD')) {
                    return $ok('main');
                }

                if (str_contains($line, 'rev-parse HEAD') || str_contains($line, 'rev-parse origin/')) {
                    return $ok(str_repeat('a', 40));
                }

                // Clean working tree.
                return $ok();
            }
        };
    }

    private function fetchCount(UpdateService $service): int
    {
        return count(array_filter(
            $service->commands,
            static fn (string $cmd): bool => str_contains($cmd, 'git fetch origin')
        ));
    }

    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }

    // ------------------------------------------------------------------
    // Fetch throttle
    // ------------------------------------------------------------------

    public function test_a_second_check_within_the_window_does_not_hit_the_network(): void
    {
        $service = $this->gitService();

        $first  = $service->check();
        $second = $service->check();

        $this->assertSame('up_to_date', $first['status']);
        $this->assertSame('up_to_date', $second['status']);
        $this->assertSame(1, $this->fetchCount($service), 'Rendering the page twice must not fetch twice.');
    }

    public function test_local_git_state_is_still_read_on_every_check(): void
    {
        $service = $this->gitService();

        $service->check();
        $service->check();

        // Only the network step is throttled: a tree that goes dirty between
        // renders must still be reported as dirty.
        $statusCalls = count(array_filter(
            $service->commands,
            static fn (string $cmd): bool => str_contains($cmd, 'status --porcelain')
        ));

        $this->assertGreaterThanOrEqual(2, $statusCalls);
    }

    public function test_the_explicit_check_button_always_reaches_the_network(): void
    {
        $service = $this->gitService();

        $service->check();
        $service->flushApiCache();
        $service->check();

        $this->assertSame(2, $this->fetchCount($service));
    }

    public function test_a_failed_fetch_is_not_retried_on_the_next_page_load(): void
    {
        $service = $this->gitService(fetchSucceeds: false);

        $first  = $service->check();
        $second = $service->check();

        $this->assertSame('fetch_failed', $first['status']);
        $this->assertSame('fetch_failed', $second['status'], 'The cached failure must still be reported, not silently swallowed.');
        $this->assertStringContainsString('could not resolve host', $second['message']);

        // Otherwise every page load pays the full 15 s timeout.
        $this->assertSame(1, $this->fetchCount($service));
    }

    public function test_a_failed_fetch_cools_off_faster_than_a_successful_one(): void
    {
        $this->assertLessThan(
            UpdateService::FETCH_THROTTLE_SECONDS,
            UpdateService::FETCH_FAILURE_COOLOFF_SECONDS,
            'A broken network must recover sooner than a successful fetch goes stale.'
        );
    }

    // ------------------------------------------------------------------
    // Redaction
    // ------------------------------------------------------------------

    public function test_url_userinfo_is_stripped(): void
    {
        $this->assertSame(
            "fatal: unable to access 'https://***@github.com/o/r.git/': 403",
            SecretRedactor::redact("fatal: unable to access 'https://sanat:ghp_AbCdEf0123456789XyZ@github.com/o/r.git/': 403")
        );

        $this->assertSame(
            'origin  https://***@github.com/o/r.git (fetch)',
            SecretRedactor::redact('origin  https://user:tok@github.com/o/r.git (fetch)')
        );

        $this->assertSame('ssh://***@github.com/o/r.git', SecretRedactor::redact('ssh://git@github.com/o/r.git'));
    }

    public function test_bare_github_tokens_are_stripped_wherever_they_appear(): void
    {
        $this->assertSame('token *** here', SecretRedactor::redact('token ghp_AbCdEf0123456789XyZ here'));
        $this->assertSame('token *** here', SecretRedactor::redact('token github_pat_11ABCDEFG0abcdefghijklmnop here'));
        $this->assertSame('*** and ***', SecretRedactor::redact('gho_AbCdEf0123456789XyZ and ghs_AbCdEf0123456789XyZ'));
    }

    public function test_ordinary_output_is_left_alone(): void
    {
        // Over-eager redaction makes real failures undiagnosable.
        foreach ([
            'https://github.com/Sanat-das/Manage-Hosting-CRM.git',
            'Everything up-to-date',
            ' M app/Services/System/UpdateService.php',
            'Your branch is behind by 3 commits',
            '',
        ] as $clean) {
            $this->assertSame($clean, SecretRedactor::redact($clean));
        }
    }

    public function test_process_output_is_redacted_before_it_reaches_the_caller(): void
    {
        // Exercises the real choke point: runRaw() is where every command's
        // output enters UpdateService, and everything downstream — result
        // messages, update.log, activity_log.metadata — trusts it to be clean.
        $service = new UpdateService;

        $result = $this->invokePrivate($service, 'runRaw', [
            [PHP_BINARY, '-r', 'echo "https://u:ghp_AAAAAAAAAAAAAAAAAA@github.com/o/r.git";'],
            15,
        ]);

        $this->assertTrue($result['success'], 'Test setup: the probe command should run.');
        $this->assertSame('https://***@github.com/o/r.git', trim($result['output']));
        $this->assertStringNotContainsString('ghp_', $result['output']);
    }
}
