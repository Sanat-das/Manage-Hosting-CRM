<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for the About & Updates page update flow.
 *
 * UpdateService is bound to a fake in the container so no git process or
 * real HTTP call ever runs. The tests confirm the controller passes the
 * check result through to the view and that the blade renders the correct
 * status badge and commit list.
 */
final class SystemPageUpdateFlowTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function adminUser(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $role  = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $admin->roles()->syncWithoutDetaching($role);

        $perm = Permission::firstOrCreate(['name' => 'system.view'], ['label' => 'View System & About']);
        $role->permissions()->syncWithoutDetaching($perm);

        return $admin;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function checkResult(array $overrides = []): array
    {
        return array_merge([
            'status'          => 'up_to_date',
            'message'         => 'Up to date.',
            'behind'          => 0,
            'commits'         => [],
            'diffStat'        => null,
            'localHash'       => 'abc1234',
            'remoteHash'      => 'abc1234',
            'branch'          => 'main',
            'remoteSanitized' => 'https://github.com/Sanat-das/Manage-Hosting-CRM',
            'remoteUrlRaw'    => 'https://github.com/Sanat-das/Manage-Hosting-CRM.git',
            'dirty'           => false,
        ], $overrides);
    }

    private function fakeCommits(int $n): array
    {
        $commits = [];
        for ($i = 0; $i < $n; $i++) {
            $hash = str_pad((string) $i, 40, 'f');
            $commits[] = [
                'hash'    => $hash,
                'short'   => substr($hash, 0, 7),
                'message' => "feat: release commit $i",
                'author'  => 'Test Author',
                'date'    => '2026-09-03T12:00:00Z',
            ];
        }
        return $commits;
    }

    private function bindFakeUpdater(array $result): void
    {
        $fake = new class($result) extends UpdateService {
            public function __construct(private readonly array $result) {}
            public function check(): array { return $this->result; }
            protected function isGitRepo(): bool { return false; }
        };
        $this->app->instance(UpdateService::class, $fake);
    }

    // ------------------------------------------------------------------
    // GET /admin/system — page renders
    // ------------------------------------------------------------------

    public function test_up_to_date_badge_renders_on_index(): void
    {
        $this->bindFakeUpdater($this->checkResult());

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('up_to_date');
        $response->assertDontSee('commit(s) behind');
    }

    public function test_behind_badge_shows_correct_count_on_index(): void
    {
        $commits = $this->fakeCommits(4);
        $this->bindFakeUpdater($this->checkResult([
            'status'  => 'behind',
            'message' => 'You are 4 commits behind origin/main.',
            'behind'  => 4,
            'commits' => $commits,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('behind');
        $response->assertSee('4 commit(s) behind');
        // Each commit message appears in the table
        foreach ($commits as $c) {
            $response->assertSee($c['message']);
            $response->assertSee($c['short']);
        }
    }

    public function test_no_git_with_github_api_fallback_shows_behind_count(): void
    {
        $commits = $this->fakeCommits(3);
        $this->bindFakeUpdater($this->checkResult([
            'status'          => 'no_git',
            'message'         => 'This is a ZIP/manual install (local version 1.0.0). Latest on GitHub is fff0000 from 2026-09-03T12:00:00Z — download the latest ZIP from GitHub, replace files (keep .env, storage/, install.lock), then run composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear.',
            'behind'          => 3,
            'commits'         => $commits,
            'branch'          => 'main',
            'remoteSanitized' => 'https://github.com/Sanat-das/Manage-Hosting-CRM',
            'remoteUrlRaw'    => 'https://github.com/Sanat-das/Manage-Hosting-CRM.git',
            'dirty'           => null,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('no_git');
        $response->assertSee('3 commit(s) behind');
        $response->assertSee('ZIP/manual install');
        foreach ($commits as $c) {
            $response->assertSee($c['message']);
        }
    }

    public function test_no_git_without_api_fallback_shows_no_behind_count(): void
    {
        $this->bindFakeUpdater($this->checkResult([
            'status'          => 'no_git',
            'message'         => 'This installation was not deployed via git. To update, download the latest ZIP from GitHub.',
            'behind'          => 0,
            'commits'         => [],
            'branch'          => null,
            'remoteSanitized' => null,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('no_git');
        $response->assertDontSee('commit(s) behind');
        $response->assertSee('not deployed via git');
    }

    // ------------------------------------------------------------------
    // POST /admin/system/check — check endpoint
    // ------------------------------------------------------------------

    public function test_check_post_redirects_to_updates_tab_with_result(): void
    {
        $this->bindFakeUpdater($this->checkResult([
            'status' => 'behind',
            'behind' => 2,
            'commits' => $this->fakeCommits(2),
        ]));

        $response = $this->actingAs($this->adminUser())
            ->post(route('admin.system.check'));

        $response->assertRedirect();
        $response->assertSessionHas('check_result');
        $this->assertSame('behind', session('check_result')['status']);
        $this->assertSame(2, session('check_result')['behind']);
    }

    public function test_check_post_returns_json_when_requested(): void
    {
        $this->bindFakeUpdater($this->checkResult([
            'status' => 'up_to_date',
            'behind' => 0,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->postJson(route('admin.system.check'));

        $response->assertOk();
        $response->assertJsonPath('status', 'up_to_date');
        $response->assertJsonPath('behind', 0);
    }

    // ------------------------------------------------------------------
    // Session flash — check result persists through redirect
    // ------------------------------------------------------------------

    public function test_github_rate_limit_fallback_renders_without_crash(): void
    {
        // When the GitHub API is rate-limited, UpdateService falls back to the
        // plain no-git result (behind=0, empty commits). The page must render a
        // 200 with the no_git badge and the manual-update instructions — no 500.
        $this->bindFakeUpdater($this->checkResult([
            'status'          => 'no_git',
            'message'         => 'This installation was not deployed via git. To update, download the latest ZIP from GitHub, replace files (keep .env, storage/, install.lock), then run composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear.',
            'behind'          => 0,
            'commits'         => [],
            'branch'          => null,
            'remoteSanitized' => null,
            'remoteUrlRaw'    => null,
            'dirty'           => null,
        ]));

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('no_git');
        $response->assertDontSee('commit(s) behind');
        $response->assertSee('not deployed via git');
        $response->assertSee('Check for updates');
    }

    public function test_check_result_from_session_is_used_in_view(): void
    {
        // Bind an up_to_date service but flash a "behind" result into session
        $this->bindFakeUpdater($this->checkResult());

        $commits = $this->fakeCommits(2);
        $flashedResult = $this->checkResult([
            'status'  => 'behind',
            'behind'  => 2,
            'commits' => $commits,
            'message' => 'You are 2 commits behind.',
        ]);

        $response = $this->actingAs($this->adminUser())
            ->withSession(['check_result' => $flashedResult])
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('2 commit(s) behind');
        $response->assertSee($commits[0]['message']);
    }
}
