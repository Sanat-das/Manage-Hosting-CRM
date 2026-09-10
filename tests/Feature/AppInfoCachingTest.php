<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * Which half of the About card may be served from cache.
 *
 * Measured on this host: of the 1913 ms all() costs, gitInfo() is 1701 ms and
 * version() 239 ms — while health(), framework() and changelog() together are
 * ~20 ms. So only the git-derived fields are cached. health() in particular
 * must stay live: it reads the scheduler heartbeat straight out of the cache
 * the cron tick writes, and it is the field an operator checks to answer "is
 * cron running right now".
 */
final class AppInfoCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        // Explicit, so a failure mid-travel cannot leak a frozen clock into the
        // next test in the file.
        $this->travelBack();

        parent::tearDown();
    }

    /** @return array{version: string, git: array<string, mixed>} */
    private function fakeSnapshot(): array
    {
        return [
            'version' => 'cached99',
            'git' => [
                'branch' => 'cached-branch', 'commit' => str_repeat('c', 40), 'short' => 'ccccccc',
                'date' => '2026-01-01', 'dirty' => false, 'remote' => null, 'remoteUrlRaw' => null,
                'ahead' => 0, 'behind' => 0,
            ],
        ];
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $role  = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $admin->roles()->syncWithoutDetaching($role);

        $perm = Permission::firstOrCreate(['name' => 'system.view'], ['label' => 'View System & About']);
        $role->permissions()->syncWithoutDetaching($perm);

        return $admin;
    }

    // ------------------------------------------------------------------
    // What gets cached
    // ------------------------------------------------------------------

    public function test_the_git_snapshot_is_cached_after_the_first_call(): void
    {
        $this->assertFalse(Cache::has(AppInfoService::GIT_CACHE_KEY));

        (new AppInfoService)->all();

        $this->assertTrue(Cache::has(AppInfoService::GIT_CACHE_KEY));
    }

    public function test_only_the_git_derived_fields_are_cached(): void
    {
        (new AppInfoService)->all();

        $cached = Cache::get(AppInfoService::GIT_CACHE_KEY);

        // Caching health() would freeze the scheduler heartbeat to save ~10 ms.
        $this->assertSame(['version', 'git'], array_keys($cached));
    }

    public function test_a_cached_snapshot_is_served_instead_of_re_running_git(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        $info = (new AppInfoService)->all();

        $this->assertSame('cached99', $info['version']);
        $this->assertSame('cached-branch', $info['git']['branch']);
    }

    public function test_live_diagnostics_are_not_served_from_the_snapshot(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        $info = (new AppInfoService)->all();

        // Computed fresh on every render even while the git half is cached.
        $this->assertArrayHasKey('scheduler', $info['health']);
        $this->assertArrayHasKey('preflight', $info['health']);
        $this->assertArrayHasKey('php', $info['framework']);
        $this->assertArrayHasKey('app', $info);
    }

    public function test_a_malformed_snapshot_is_ignored_rather_than_rendered(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, ['version' => 'only-half'], 60);

        $info = (new AppInfoService)->all();

        $this->assertNotSame('only-half', $info['version']);
        $this->assertArrayHasKey('git', $info);
    }

    // ------------------------------------------------------------------
    // TTL
    // ------------------------------------------------------------------

    public function test_the_snapshot_lives_for_its_ttl_and_no_longer(): void
    {
        $this->assertSame(60, AppInfoService::GIT_CACHE_SECONDS, 'The documented window is 60 s.');

        // Populated through the production path, so this asserts the TTL the
        // service actually passes to Cache::put — not one the test chose.
        (new AppInfoService)->all();

        $this->assertTrue(Cache::has(AppInfoService::GIT_CACHE_KEY));

        $this->travel(AppInfoService::GIT_CACHE_SECONDS - 1)->seconds();
        $this->assertTrue(
            Cache::has(AppInfoService::GIT_CACHE_KEY),
            'Expiring early would quietly undo the optimisation.'
        );

        $this->travel(2)->seconds();
        $this->assertFalse(
            Cache::has(AppInfoService::GIT_CACHE_KEY),
            'A snapshot that outlives its window would keep serving a stale commit and dirty flag.'
        );
    }

    public function test_an_expired_snapshot_is_recomputed_rather_than_served(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), AppInfoService::GIT_CACHE_SECONDS);

        $this->travel(AppInfoService::GIT_CACHE_SECONDS + 1)->seconds();

        $info = (new AppInfoService)->all();

        // Expiry has to mean "go and look again", not "keep the last answer".
        $this->assertNotSame('cached99', $info['version']);
        $this->assertNotSame('cached-branch', $info['git']['branch']);

        // And the fresh result is cached again for the next window.
        $this->assertTrue(Cache::has(AppInfoService::GIT_CACHE_KEY));
        $this->assertNotSame('cached99', Cache::get(AppInfoService::GIT_CACHE_KEY)['version']);
    }

    // ------------------------------------------------------------------
    // Invalidation
    // ------------------------------------------------------------------

    public function test_flush_cache_drops_the_snapshot(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        AppInfoService::flushCache();

        $this->assertFalse(Cache::has(AppInfoService::GIT_CACHE_KEY));
    }

    public function test_anything_that_takes_the_update_lock_invalidates_the_snapshot(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        // run(), runZip() and rollback() all funnel through withUpdateLock(),
        // so covering it covers every mutating entry point at once.
        $service = new UpdateService;
        $method  = new ReflectionMethod($service, 'withUpdateLock');
        $method->setAccessible(true);
        $method->invokeArgs($service, [
            static fn (): string => 'done',
            static fn (): string => 'busy',
        ]);

        $this->assertFalse(
            Cache::has(AppInfoService::GIT_CACHE_KEY),
            'An update changes the commit and the dirty flag the card caches.'
        );
    }

    public function test_the_snapshot_is_invalidated_even_when_the_update_fails(): void
    {
        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        $service = new UpdateService;
        $method  = new ReflectionMethod($service, 'withUpdateLock');
        $method->setAccessible(true);

        try {
            $method->invokeArgs($service, [
                static function (): string { throw new \RuntimeException('boom'); },
                static fn (): string => 'busy',
            ]);
            $this->fail('Expected the callback to throw.');
        } catch (\RuntimeException) {
            // Expected.
        }

        // A half-finished run is when a stale card misleads most.
        $this->assertFalse(Cache::has(AppInfoService::GIT_CACHE_KEY));
    }

    public function test_the_check_for_updates_action_serves_fresh_data(): void
    {
        // A fake updater so the request runs no git and touches no network.
        $this->app->instance(UpdateService::class, new class extends UpdateService
        {
            public function check(): array
            {
                return [
                    'status' => 'up_to_date', 'message' => 'Up to date.', 'behind' => 0, 'commits' => [],
                    'diffStat' => null, 'localHash' => 'abc1234', 'remoteHash' => 'abc1234', 'branch' => 'main',
                    'remoteSanitized' => null, 'remoteUrlRaw' => null, 'dirty' => false,
                ];
            }
        });

        Cache::put(AppInfoService::GIT_CACHE_KEY, $this->fakeSnapshot(), 60);

        $this->actingAs($this->adminUser())
            ->post(route('admin.system.check'))
            ->assertRedirect();

        $this->assertFalse(
            Cache::has(AppInfoService::GIT_CACHE_KEY),
            'Pressing "Check for updates" must not answer from a cache.'
        );
    }
}
