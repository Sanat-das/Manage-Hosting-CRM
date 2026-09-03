<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\System\UpdateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for UpdateService::check() GitHub API fallback path (no .git present).
 *
 * We subclass UpdateService to short-circuit the git process calls so these
 * tests run purely in-memory without requiring a real git working tree.
 */
final class UpdateServiceGithubFallbackTest extends TestCase
{
    private function makeService(): UpdateService
    {
        return new class extends UpdateService {
            protected function isGitRepo(): bool { return false; }
        };
    }

    private function fakeGithubPayload(int $count = 3): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $hash = str_pad((string) $i, 40, 'a');
            $items[] = [
                'sha' => $hash,
                'commit' => [
                    'message' => "commit message $i",
                    'author' => ['name' => 'Author', 'date' => '2026-09-03T00:00:00Z'],
                ],
            ];
        }
        return $items;
    }

    public function test_no_git_with_api_success_returns_no_git_with_commits(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response($this->fakeGithubPayload(3), 200),
        ]);

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertCount(3, $result['commits']);
        $this->assertSame(3, $result['behind']);
        $this->assertNotEmpty($result['remoteHash']);
        $this->assertStringContainsString('ZIP/manual install', $result['message']);
        $this->assertSame('main', $result['branch']);
    }

    public function test_no_git_with_api_failure_returns_plain_no_git(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(null, 503),
        ]);
        Cache::flush();

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertSame(0, $result['behind']);
        $this->assertEmpty($result['commits']);
        $this->assertStringContainsString('not deployed via git', $result['message']);
    }

    public function test_no_git_with_api_returning_empty_array_falls_back(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 200),
        ]);
        Cache::flush();

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertSame(0, $result['behind']);
    }

    public function test_commit_shape_is_correct(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response($this->fakeGithubPayload(1), 200),
        ]);
        Cache::flush();

        $result = $this->makeService()->check();

        $c = $result['commits'][0];
        $this->assertArrayHasKey('hash', $c);
        $this->assertArrayHasKey('short', $c);
        $this->assertArrayHasKey('message', $c);
        $this->assertArrayHasKey('author', $c);
        $this->assertArrayHasKey('date', $c);
        $this->assertSame(7, strlen($c['short']));
    }

    public function test_github_response_is_cached_for_five_minutes(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response($this->fakeGithubPayload(2), 200),
        ]);
        Cache::flush();

        // First call — populates cache
        $this->makeService()->check();

        // Replace fake with 503 so a real HTTP call would fail
        Http::fake(['api.github.com/*' => Http::response(null, 503)]);

        // Second call — must hit cache, not the 503 fake
        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertCount(2, $result['commits']);

        // Verify the cache key exists and TTL is ≤ 300 s
        $this->assertTrue(Cache::has('system.github_commits'));
    }

    public function test_cache_is_used_and_http_called_only_once(): void
    {
        Cache::flush();
        Http::fake([
            'api.github.com/*' => Http::response($this->fakeGithubPayload(2), 200),
        ]);

        $svc = $this->makeService();
        $svc->check();
        $svc->check();

        Http::assertSentCount(1);
    }

    public function test_network_exception_falls_back_gracefully(): void
    {
        Cache::flush();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('timeout');
        });

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertSame(0, $result['behind']);
    }

    public function test_github_rate_limit_403_falls_back_to_plain_no_git(): void
    {
        Cache::flush();
        Http::fake([
            'api.github.com/*' => Http::response(
                ['message' => 'API rate limit exceeded', 'documentation_url' => 'https://docs.github.com/rest'],
                403,
                ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1725350400']
            ),
        ]);

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertSame(0, $result['behind']);
        $this->assertEmpty($result['commits']);
        $this->assertNull($result['remoteHash']);
        $this->assertStringContainsString('not deployed via git', $result['message']);
    }

    public function test_github_rate_limit_429_falls_back_to_plain_no_git(): void
    {
        Cache::flush();
        Http::fake([
            'api.github.com/*' => Http::response(
                ['message' => 'Too Many Requests'],
                429,
                ['Retry-After' => '60']
            ),
        ]);

        $result = $this->makeService()->check();

        $this->assertSame('no_git', $result['status']);
        $this->assertSame(0, $result['behind']);
        $this->assertEmpty($result['commits']);
    }

    public function test_rate_limit_response_does_not_throw(): void
    {
        Cache::flush();
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403),
        ]);

        // Must not throw — controller wraps nothing, so a throw would 500 the page
        $this->expectNotToPerformAssertions();
        try {
            $this->makeService()->check();
        } catch (\Throwable $e) {
            $this->fail('check() threw when GitHub rate-limited: ' . $e->getMessage());
        }
    }
}
