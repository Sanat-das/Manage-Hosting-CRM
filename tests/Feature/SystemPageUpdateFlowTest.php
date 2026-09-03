<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
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
                'message' => "Release commit $i",
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

    /**
     * A ZIP install's app info: no .git, so every gitInfo() field is null.
     *
     * @return array<string, mixed>
     */
    private function zipInstallAppInfo(string $version = 'abc1234'): array
    {
        return [
            'app' => ['name' => 'ManageHosting', 'env' => 'production', 'debug' => false, 'url' => 'https://example.test', 'timezone' => 'UTC', 'locale' => 'en', 'installed' => true, 'installedAt' => null, 'maintenance' => false],
            'version' => $version,
            'git' => ['branch' => null, 'commit' => null, 'short' => null, 'date' => null, 'dirty' => null, 'remote' => null, 'remoteUrlRaw' => null, 'ahead' => null, 'behind' => null],
            'health' => ['preflight' => [], 'scheduler' => ['lastTickAt' => null, 'schedulerIsHealthy' => false, 'staleAfter' => 300, 'paused' => false]],
            'framework' => ['laravel' => '13.0.0', 'php' => PHP_VERSION, 'composerHash' => null, 'packages' => []],
            'changelog' => '',
        ];
    }

    /**
     * Render the page directly. AppInfoService is final, so rather than weaken
     * it for testing, feed the blade the data the controller would pass — the
     * blade is what decides how a ZIP install is described.
     *
     * @param  array<string, mixed>  $appInfo
     * @param  array<string, mixed>  $check
     */
    private function renderSystemPage(array $appInfo, array $check, string $tab = 'about'): string
    {
        $this->actingAs($this->adminUser());

        return view('admin.system.index', [
            'appInfo'   => $appInfo,
            'check'     => $check,
            'history'   => collect(),
            'activeTab' => $tab,
            // Normally shared by ShareErrorsFromSession during a real request.
            'errors'    => new ViewErrorBag(),
        ])->render();
    }

    // ------------------------------------------------------------------
    // GET /admin/system — page renders
    // ------------------------------------------------------------------

    public function test_zip_install_about_card_agrees_with_updates_tab_when_up_to_date(): void
    {
        // A ZIP install has no git metadata, so the About card used to render
        // "Unknown" / "—" while the Updates tab reported it as up to date.
        $html = $this->renderSystemPage(
            $this->zipInstallAppInfo('abc1234'),
            $this->checkResult(['status' => 'up_to_date', 'message' => 'Up to date (version abc1234).'])
        );

        $this->assertStringContainsString('Source (ZIP install)', $html);
        $this->assertStringContainsString('Up to date', $html);
        $this->assertStringContainsString('abc1234', $html);
        $this->assertStringNotContainsString('Unknown</span>', $html, 'The About card must not claim ignorance about an install the Updates tab can describe.');
    }

    public function test_zip_install_about_card_reports_pending_update_count(): void
    {
        $html = $this->renderSystemPage(
            $this->zipInstallAppInfo('abc1234'),
            $this->checkResult([
                'status'  => 'no_git',
                'behind'  => 3,
                'commits' => $this->fakeCommits(3),
            ])
        );

        $this->assertStringContainsString('3 behind', $html);
    }

    public function test_git_checkout_about_card_still_uses_git_metadata(): void
    {
        // The ZIP fallbacks must not shadow a real checkout's own git state.
        $appInfo = $this->zipInstallAppInfo('v2.0.0');
        $appInfo['git'] = ['branch' => 'develop', 'commit' => str_repeat('a', 40), 'short' => 'aaaaaaa', 'date' => '2026-09-03', 'dirty' => true, 'remote' => 'https://example.test/repo.git', 'remoteUrlRaw' => 'https://example.test/repo.git', 'ahead' => 1, 'behind' => 2];

        $html = $this->renderSystemPage($appInfo, $this->checkResult(['status' => 'behind', 'behind' => 2, 'branch' => 'main']));

        $this->assertStringContainsString('develop', $html, 'A real checkout must show its own branch, not the check fallback.');
        $this->assertStringContainsString('Dirty', $html);
        $this->assertStringNotContainsString('Source (ZIP install)', $html);
    }

    public function test_up_to_date_badge_renders_on_index(): void
    {
        $this->bindFakeUpdater($this->checkResult());

        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.system.index', ['tab' => 'updates']));

        $response->assertOk();
        $response->assertSee('Your application is up to date');
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
        $response->assertSee('An update is available');
        $response->assertSee('4 improvements are ready');
        // Each commit message appears in the What's new list
        foreach ($commits as $c) {
            $response->assertSee($c['message']);
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
        $response->assertSee('An update is available');
        $response->assertSee('3 improvements are ready');
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
        $response->assertSee('Check for updates');
        $response->assertDontSee('commit(s) behind');
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
        $response->assertSee('Check for updates');
        $response->assertDontSee('commit(s) behind');
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
        $response->assertSee('2 improvements are ready');
        $response->assertSee($commits[0]['message']);
    }
}
