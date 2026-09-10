<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\System\UpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * The mutating half of UpdateService: deploy, download validation, the update
 * lock, and ZIP rollback.
 *
 * Everything here runs against temporary directories and a stubbed runProcess(),
 * so no git, composer, artisan or network call is ever made — and, critically,
 * nothing is ever written into the real installation.
 */
final class UpdateServiceDeploySafetyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->deleteTree($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function tempDir(string $label): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mh_test_' . $label . '_' . bin2hex(random_bytes(5));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $contents);
    }

    /** Invoke a private/protected method without widening its visibility for production code. */
    private function invokePrivate(UpdateService $service, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $args);
    }

    /** Same, for methods taking an argument by reference (syncDeploy's manifest). */
    private function callSyncDeploy(UpdateService $service, string $src, string $dest, array $preserve, ?string $backup, array &$manifest): void
    {
        $reflection = new ReflectionMethod($service, 'syncDeploy');
        $reflection->setAccessible(true);

        $args = [$src, $dest, $preserve, $backup, &$manifest, null];
        $reflection->invokeArgs($service, $args);
    }

    /**
     * A service whose every external process call succeeds silently.
     *
     * $pending defaults to the sentinel `false`, meaning "use the real
     * migration check against the test database". Pass an array to force it.
     */
    private function stubbedService(?string $appRoot = null, ?string $restoreRoot = null, array|null|false $pending = false): UpdateService
    {
        return new class($appRoot, $restoreRoot, $pending) extends UpdateService {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(
                private readonly ?string $rootOverride,
                private readonly ?string $restoreOverride,
                private readonly array|null|false $pendingOverride = false,
            ) {}

            protected function pendingMigrations(): ?array
            {
                return $this->pendingOverride === false
                    ? parent::pendingMigrations()
                    : $this->pendingOverride;
            }

            protected function isGitRepo(): bool
            {
                return false;
            }

            protected function appRoot(): string
            {
                return $this->rootOverride ?? parent::appRoot();
            }

            protected function restorePointRoot(): string
            {
                return $this->restoreOverride ?? parent::restorePointRoot();
            }

            protected function composerAvailable(): bool
            {
                return false;
            }

            protected function runProcess(array $cmd, int $timeout = 3): array
            {
                $this->commands[] = implode(' ', $cmd);

                return ['output' => '', 'exit' => 0, 'success' => true];
            }
        };
    }

    // ------------------------------------------------------------------
    // syncDeploy: manifest, preserve list, unchanged files
    // ------------------------------------------------------------------

    public function test_deploy_records_changed_and_added_files_and_snapshots_the_originals(): void
    {
        $src    = $this->tempDir('src');
        $dest   = $this->tempDir('dest');
        $backup = $this->tempDir('backup');

        $this->writeFile($src . '/app/Existing.php', 'new contents');
        $this->writeFile($src . '/app/Brand New.php', 'brand new');
        $this->writeFile($dest . '/app/Existing.php', 'old contents');

        $manifest = [];
        $this->callSyncDeploy($this->stubbedService(), $src, $dest, [], $backup, $manifest);

        $this->assertSame(['app/Existing.php'], $manifest['changed']);
        $this->assertSame(['app/Brand New.php'], $manifest['added']);

        $this->assertSame('new contents', file_get_contents($dest . '/app/Existing.php'));
        $this->assertSame('brand new', file_get_contents($dest . '/app/Brand New.php'));

        // The pre-update copy is what rollback restores from.
        $this->assertSame('old contents', file_get_contents($backup . '/app/Existing.php'));
    }

    public function test_deploy_skips_identical_files_rather_than_snapshotting_them(): void
    {
        $src    = $this->tempDir('src');
        $dest   = $this->tempDir('dest');
        $backup = $this->tempDir('backup');

        $this->writeFile($src . '/vendor/big/File.php', 'unchanged between releases');
        $this->writeFile($dest . '/vendor/big/File.php', 'unchanged between releases');

        $manifest = [];
        $this->callSyncDeploy($this->stubbedService(), $src, $dest, [], $backup, $manifest);

        $this->assertSame([], $manifest['changed']);
        $this->assertSame([], $manifest['added']);
        $this->assertSame(1, $manifest['skipped']);

        // Nothing to undo, so nothing is snapshotted — this is what keeps a
        // restore point small despite the archive shipping all of vendor/.
        $this->assertFileDoesNotExist($backup . '/vendor/big/File.php');
    }

    public function test_deploy_never_touches_preserved_paths(): void
    {
        $src    = $this->tempDir('src');
        $dest   = $this->tempDir('dest');

        $this->writeFile($src . '/.env', 'APP_ENV=from-archive');
        $this->writeFile($src . '/storage/logs/laravel.log', 'archive log');
        $this->writeFile($src . '/app/Real.php', 'deploy me');

        $this->writeFile($dest . '/.env', 'APP_ENV=production');
        $this->writeFile($dest . '/storage/logs/laravel.log', 'live log');

        $manifest = [];
        $this->callSyncDeploy($this->stubbedService(), $src, $dest, ['.env', 'storage'], null, $manifest);

        $this->assertSame('APP_ENV=production', file_get_contents($dest . '/.env'));
        $this->assertSame('live log', file_get_contents($dest . '/storage/logs/laravel.log'));
        $this->assertSame('deploy me', file_get_contents($dest . '/app/Real.php'));
        $this->assertSame(['app/Real.php'], $manifest['added']);
    }

    // ------------------------------------------------------------------
    // syncDeploy: failed writes
    // ------------------------------------------------------------------

    public function test_deploy_throws_when_a_file_cannot_be_written(): void
    {
        $src  = $this->tempDir('src');
        $dest = $this->tempDir('dest');

        $this->writeFile($src . '/app/Blocked.php', 'contents');

        // A directory sitting where the file must go makes copy() fail the same
        // way a locked file or a denied ACL does.
        mkdir($dest . '/app/Blocked.php', 0755, true);

        $manifest = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Blocked\.php/');

        $this->callSyncDeploy($this->stubbedService(), $src, $dest, [], null, $manifest);
    }

    public function test_manifest_survives_a_mid_deploy_failure(): void
    {
        $src    = $this->tempDir('src');
        $dest   = $this->tempDir('dest');
        $backup = $this->tempDir('backup');

        $this->writeFile($src . '/a.php', 'new a');
        $this->writeFile($src . '/z.php', 'new z');
        $this->writeFile($dest . '/a.php', 'old a');
        mkdir($dest . '/z.php', 0755, true);

        $manifest = [];

        try {
            $this->callSyncDeploy($this->stubbedService(), $src, $dest, [], $backup, $manifest);
            $this->fail('Expected the blocked write to throw.');
        } catch (RuntimeException) {
            // Expected.
        }

        // The manifest is written before each copy is attempted and passed by
        // reference, so the caller still knows what was touched after the throw
        // — that is what makes the restore point usable after a half-deploy.
        // Asserting on the blocked file keeps this independent of readdir order.
        $this->assertContains('z.php', $manifest['added']);

        // Whatever did land first must have been snapshotted before being overwritten.
        if (in_array('a.php', $manifest['changed'], true)) {
            $this->assertSame('old a', file_get_contents($backup . '/a.php'));
        }
    }

    // ------------------------------------------------------------------
    // Download validation
    // ------------------------------------------------------------------

    public function test_a_github_error_body_is_not_accepted_as_an_archive(): void
    {
        $dir  = $this->tempDir('dl');
        $path = $dir . '/update.zip';

        // What curl without --fail used to leave on disk, exit code 0 and all.
        file_put_contents($path, '{"message":"API rate limit exceeded","documentation_url":"https://docs.github.com"}');

        $this->assertFalse($this->invokePrivate($this->stubbedService(), 'isZipArchive', [$path]));
    }

    public function test_a_real_zip_archive_is_accepted(): void
    {
        $dir  = $this->tempDir('dl');
        $path = $dir . '/update.zip';

        // PK\x03\x04 header plus enough bytes to clear MIN_ZIP_BYTES.
        file_put_contents($path, "PK\x03\x04" . str_repeat("\0", UpdateService::MIN_ZIP_BYTES));

        $this->assertTrue($this->invokePrivate($this->stubbedService(), 'isZipArchive', [$path]));
    }

    public function test_a_truncated_archive_is_rejected(): void
    {
        $dir  = $this->tempDir('dl');
        $path = $dir . '/update.zip';

        file_put_contents($path, "PK\x03\x04");

        $this->assertFalse($this->invokePrivate($this->stubbedService(), 'isZipArchive', [$path]));
    }

    public function test_an_archive_without_the_app_markers_is_refused(): void
    {
        $root = $this->tempDir('root');
        $this->writeFile($root . '/README.md', 'some other project');

        $this->assertFalse($this->invokePrivate($this->stubbedService(), 'looksLikeAppRoot', [$root]));
    }

    public function test_an_archive_with_the_app_markers_is_accepted(): void
    {
        $root = $this->tempDir('root');
        $this->writeFile($root . '/artisan', '#!/usr/bin/env php');
        $this->writeFile($root . '/composer.json', '{}');
        mkdir($root . '/app', 0755, true);
        mkdir($root . '/bootstrap', 0755, true);

        $this->assertTrue($this->invokePrivate($this->stubbedService(), 'looksLikeAppRoot', [$root]));
    }

    // ------------------------------------------------------------------
    // Committed dependencies
    // ------------------------------------------------------------------

    public function test_composer_install_does_not_prune_committed_dev_dependencies(): void
    {
        $cmd = $this->invokePrivate($this->stubbedService(), 'composerInstall');

        $this->assertNotContains('--no-dev', $cmd, 'vendor/ is committed with dev packages; --no-dev deletes ~2,000 tracked files and wedges the tree permanently dirty.');
        $this->assertContains('install', $cmd);
        $this->assertContains('--optimize-autoloader', $cmd);
    }

    public function test_ignore_platform_reqs_variant_still_omits_no_dev(): void
    {
        $cmd = $this->invokePrivate($this->stubbedService(), 'composerInstall', [true]);

        $this->assertNotContains('--no-dev', $cmd);
        $this->assertContains('--ignore-platform-reqs', $cmd);
    }

    public function test_pruned_vendor_deletions_do_not_count_as_a_dirty_tree(): void
    {
        $service = $this->stubbedService();

        $this->assertTrue($this->invokePrivate($service, 'isPrunedVendorDeletion', [' D vendor/phpunit/phpunit/phpunit']));
        $this->assertTrue($this->invokePrivate($service, 'isPrunedVendorDeletion', ['D  vendor/fakerphp/faker/LICENSE']));

        // Operator work must still register as dirty.
        $this->assertFalse($this->invokePrivate($service, 'isPrunedVendorDeletion', [' M app/Services/System/UpdateService.php']));
        $this->assertFalse($this->invokePrivate($service, 'isPrunedVendorDeletion', [' D app/Models/User.php']));
        $this->assertFalse($this->invokePrivate($service, 'isPrunedVendorDeletion', [' M vendor/composer/installed.php']));
    }

    // ------------------------------------------------------------------
    // VERSION stamping
    // ------------------------------------------------------------------

    public function test_a_full_sha_is_stamped_short_and_anything_else_verbatim(): void
    {
        $appRoot = $this->tempDir('app');
        $service = $this->stubbedService($appRoot);

        $this->assertTrue($this->invokePrivate($service, 'writeVersionMarker', [str_repeat('a', 40)]));
        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));

        $this->assertTrue($this->invokePrivate($service, 'writeVersionMarker', ['1.2.3']));
        $this->assertSame('1.2.3', file_get_contents($appRoot . '/VERSION'));

        // And it reads back through the same root it was written to.
        $this->assertSame('1.2.3', $this->invokePrivate($service, 'resolveLocalVersion'));
    }

    public function test_a_git_checkout_is_never_stamped(): void
    {
        $appRoot = $this->tempDir('app');

        // VERSION is a tracked file: writing it inside a git checkout leaves the
        // tree permanently dirty, which is exactly what wedges the updater shut.
        $service = new class($appRoot) extends UpdateService
        {
            public function __construct(private readonly string $rootOverride) {}

            protected function appRoot(): string
            {
                return $this->rootOverride;
            }

            protected function isGitRepo(): bool
            {
                return true;
            }
        };

        $this->assertFalse($this->invokePrivate($service, 'writeVersionMarker', [str_repeat('a', 40)]));
        $this->assertFileDoesNotExist($appRoot . '/VERSION');
    }

    public function test_an_empty_version_is_not_stamped(): void
    {
        $appRoot = $this->tempDir('app');

        $this->assertFalse($this->invokePrivate($this->stubbedService($appRoot), 'writeVersionMarker', ['  ']));
        $this->assertFileDoesNotExist($appRoot . '/VERSION');
    }

    public function test_the_deploy_target_is_read_from_the_newest_restore_point(): void
    {
        $restoreRoot = $this->tempDir('restore');

        $this->writeFile($restoreRoot . '/20260901-100000-aaaaaa/manifest.json', (string) json_encode([
            'id' => '20260901-100000-aaaaaa', 'from' => 'old', 'to' => 'aaaaaaa',
        ]));
        $this->writeFile($restoreRoot . '/20260910-120000-bbbbbb/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => 'bbbbbbb',
        ]));

        $this->assertSame(
            'bbbbbbb',
            $this->invokePrivate($this->stubbedService(null, $restoreRoot), 'pendingVersionTarget')
        );
    }

    public function test_no_restore_points_means_no_deploy_target(): void
    {
        $this->assertNull(
            $this->invokePrivate($this->stubbedService(null, $this->tempDir('restore')), 'pendingVersionTarget')
        );
    }

    public function test_a_rolled_back_restore_point_is_not_a_deploy_target(): void
    {
        $restoreRoot = $this->tempDir('restore');

        // Rolling back deliberately backs out of `to`. Re-stamping it later
        // would claim a version the operator had just removed.
        $this->writeFile($restoreRoot . '/20260910-120000-bbbbbb/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => 'bbbbbbb',
            'restored_at' => '2026-09-10T13:00:00+00:00',
        ]));

        $this->assertNull(
            $this->invokePrivate($this->stubbedService(null, $restoreRoot), 'pendingVersionTarget')
        );
    }

    public function test_a_deploy_that_never_resolved_a_target_is_not_stamped(): void
    {
        $restoreRoot = $this->tempDir('restore');

        // Deploy failed before the target was resolved, so `to` was never set.
        $this->writeFile($restoreRoot . '/20260910-120000-bbbbbb/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => null, 'complete' => false,
        ]));

        $this->assertNull(
            $this->invokePrivate($this->stubbedService(null, $restoreRoot), 'pendingVersionTarget')
        );
    }

    public function test_a_rollback_leaves_no_deploy_target_behind_for_finalize(): void
    {
        $user        = User::factory()->create();
        $appRoot     = $this->tempDir('app');
        $restoreRoot = $this->tempDir('restore');

        $this->writeFile($appRoot . '/app/Existing.php', 'version 2');
        $this->writeFile($appRoot . '/VERSION', 'bbbbbbb');

        $point = $restoreRoot . '/20260910-120000-bbbbbb';
        $this->writeFile($point . '/files/app/Existing.php', 'version 1');
        $this->writeFile($point . '/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => 'bbbbbbb',
            'changed' => ['app/Existing.php'], 'added' => [],
        ]));

        $service = $this->stubbedService($appRoot, $restoreRoot, pending: []);
        $this->assertSame('success', $service->rollback('aaaaaaa', $user)['status']);
        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));

        // A finalize afterwards must not undo the rollback by re-stamping.
        $service->finalize();

        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));
    }

    // ------------------------------------------------------------------
    // finalize(): the manual repair path
    // ------------------------------------------------------------------

    public function test_a_manual_repair_stamps_the_version_the_deploy_was_heading_for(): void
    {
        $appRoot     = $this->tempDir('app');
        $restoreRoot = $this->tempDir('restore');

        // The state a failed migrate leaves behind: files deployed, target
        // recorded, VERSION still on the old value.
        $this->writeFile($appRoot . '/VERSION', 'aaaaaaa');
        $this->writeFile($restoreRoot . '/20260910-120000-bbbbbb/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => 'bbbbbbb',
        ]));

        $result = $this->stubbedService($appRoot, $restoreRoot, pending: [])->finalize();

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('bbbbbbb', $result['message']);

        // Without this, check() re-offered the same update forever and every run
        // re-downloaded and re-deployed byte-identical code.
        $this->assertSame('bbbbbbb', file_get_contents($appRoot . '/VERSION'));
    }

    public function test_a_repair_that_leaves_migrations_pending_does_not_stamp_the_version(): void
    {
        $appRoot     = $this->tempDir('app');
        $restoreRoot = $this->tempDir('restore');

        $this->writeFile($appRoot . '/VERSION', 'aaaaaaa');
        $this->writeFile($restoreRoot . '/20260910-120000-bbbbbb/manifest.json', (string) json_encode([
            'id' => '20260910-120000-bbbbbb', 'from' => 'aaaaaaa', 'to' => 'bbbbbbb',
        ]));

        $result = $this->stubbedService($appRoot, $restoreRoot, pending: ['2026_09_11_000000_add_thing'])->finalize();

        $this->assertSame('incomplete', $result['status']);

        // New code against an old schema must keep advertising the update.
        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));
    }

    public function test_a_repair_with_nothing_to_finish_leaves_the_version_alone(): void
    {
        $appRoot = $this->tempDir('app');
        $this->writeFile($appRoot . '/VERSION', 'aaaaaaa');

        // No restore point, so nothing was deployed that needs stamping — a
        // finalize run by hand must not claim a version off the internet.
        $result = $this->stubbedService($appRoot, $this->tempDir('restore'), pending: [])->finalize();

        $this->assertSame('success', $result['status']);
        $this->assertSame('Post-update steps completed.', $result['message']);
        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));
    }

    // ------------------------------------------------------------------
    // Concurrency
    // ------------------------------------------------------------------

    public function test_a_second_update_is_refused_while_one_holds_the_lock(): void
    {
        $user = User::factory()->create();

        $lock = Cache::lock(UpdateService::LOCK_KEY, UpdateService::LOCK_SECONDS);
        $this->assertTrue($lock->get(), 'Test setup: could not take the update lock.');

        try {
            $service = $this->stubbedService();
            $result  = $service->runZip($user);

            $this->assertSame('busy', $result['status']);
            $this->assertStringContainsString('already running', $result['message']);
            $this->assertSame([], array_filter(
                $service->commands,
                static fn (string $cmd): bool => str_contains($cmd, 'artisan down')
            ), 'A refused run must not have touched maintenance mode.');
        } finally {
            $lock->release();
        }
    }

    public function test_the_lock_is_released_after_the_work_finishes(): void
    {
        $service = $this->stubbedService();

        $this->assertSame('ran', $this->invokePrivate($service, 'withUpdateLock', [
            static fn (): string => 'ran',
            static fn (): string => 'busy',
        ]));

        $lock = Cache::lock(UpdateService::LOCK_KEY, 10);
        $this->assertTrue($lock->get(), 'The update lock was not released.');
        $lock->release();
    }

    public function test_the_lock_is_released_even_when_the_work_throws(): void
    {
        $service = $this->stubbedService();

        try {
            $this->invokePrivate($service, 'withUpdateLock', [
                static function (): string { throw new RuntimeException('boom'); },
                static fn (): string => 'busy',
            ]);
            $this->fail('Expected the callback to throw.');
        } catch (RuntimeException) {
            // Expected.
        }

        $lock = Cache::lock(UpdateService::LOCK_KEY, 10);
        $this->assertTrue($lock->get(), 'A failed update left the lock held.');
        $lock->release();
    }

    public function test_the_lock_is_reentrant_so_run_can_delegate_to_run_zip(): void
    {
        $service = $this->stubbedService();

        // run() takes the lock and then calls runZip(), which takes it again.
        // Without re-entrancy that inner call would report itself as busy.
        $inner = $this->invokePrivate($service, 'withUpdateLock', [
            fn (): string => $this->invokePrivate($service, 'withUpdateLock', [
                static fn (): string => 'inner ran',
                static fn (): string => 'inner refused',
            ]),
            static fn (): string => 'outer refused',
        ]);

        $this->assertSame('inner ran', $inner);
    }

    // ------------------------------------------------------------------
    // ZIP rollback
    // ------------------------------------------------------------------

    public function test_zip_rollback_restores_overwritten_files_and_removes_added_ones(): void
    {
        $user        = User::factory()->create();
        $appRoot     = $this->tempDir('app');
        $restoreRoot = $this->tempDir('restore');

        // State after an update: Existing.php was overwritten, Added.php is new.
        $this->writeFile($appRoot . '/app/Existing.php', 'version 2');
        $this->writeFile($appRoot . '/app/Added.php', 'only in version 2');
        $this->writeFile($appRoot . '/VERSION', 'bbbbbbb');

        $point = $restoreRoot . '/20260910-120000-abc123';
        $this->writeFile($point . '/files/app/Existing.php', 'version 1');
        $this->writeFile($point . '/manifest.json', (string) json_encode([
            'id'      => '20260910-120000-abc123',
            'method'  => 'zip',
            'from'    => 'aaaaaaa',
            'to'      => 'bbbbbbb',
            'changed' => ['app/Existing.php'],
            'added'   => ['app/Added.php'],
        ]));

        $result = $this->stubbedService($appRoot, $restoreRoot)->rollback('aaaaaaa', $user);

        $this->assertSame('success', $result['status']);
        $this->assertSame('version 1', file_get_contents($appRoot . '/app/Existing.php'));
        $this->assertFileDoesNotExist($appRoot . '/app/Added.php');

        // The version marker goes back too, or check() keeps advertising the
        // update that was just undone.
        $this->assertSame('aaaaaaa', file_get_contents($appRoot . '/VERSION'));

        $this->assertDatabaseHas('activity_log', ['action' => 'system.rolledback']);
    }

    public function test_zip_rollback_reports_when_no_restore_point_exists(): void
    {
        $user = User::factory()->create();

        $result = $this->stubbedService($this->tempDir('app'), $this->tempDir('restore'))->rollback('aaaaaaa', $user);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('No restore point', $result['message']);
    }

    public function test_zip_rollback_names_the_available_points_when_the_target_is_unknown(): void
    {
        $user        = User::factory()->create();
        $restoreRoot = $this->tempDir('restore');

        $point = $restoreRoot . '/20260910-120000-abc123';
        $this->writeFile($point . '/manifest.json', (string) json_encode([
            'id' => '20260910-120000-abc123', 'from' => 'aaaaaaa', 'changed' => [], 'added' => [],
        ]));

        $result = $this->stubbedService($this->tempDir('app'), $restoreRoot)->rollback('9999999', $user);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('20260910-120000-abc123', $result['message']);
    }

    public function test_zip_rollback_fails_loudly_when_the_snapshot_is_incomplete(): void
    {
        $user        = User::factory()->create();
        $appRoot     = $this->tempDir('app');
        $restoreRoot = $this->tempDir('restore');

        $this->writeFile($appRoot . '/app/Existing.php', 'version 2');

        // Manifest claims a file the snapshot does not hold.
        $point = $restoreRoot . '/20260910-120000-abc123';
        $this->writeFile($point . '/manifest.json', (string) json_encode([
            'id' => '20260910-120000-abc123', 'from' => 'aaaaaaa', 'changed' => ['app/Existing.php'], 'added' => [],
        ]));

        $result = $this->stubbedService($appRoot, $restoreRoot)->rollback('aaaaaaa', $user);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('missing', $result['message']);

        // Nothing half-restored and silently called a success.
        $this->assertSame('version 2', file_get_contents($appRoot . '/app/Existing.php'));
    }

    // ------------------------------------------------------------------
    // Pending-migration detection
    // ------------------------------------------------------------------

    public function test_a_fully_migrated_database_reports_no_pending_migrations(): void
    {
        // RefreshDatabase has just run every migration, so this is exactly the
        // state a successful update ends in. The old string match against
        // "Nothing to migrate" could report it as pending and turn the update
        // into a reported failure.
        $service = $this->stubbedService();

        $this->assertSame([], $this->invokePrivate($service, 'pendingMigrations'));
    }

    public function test_pending_detection_does_not_depend_on_command_output_wording(): void
    {
        // runProcess() is stubbed to return empty output and exit 0 — which is
        // what used to be read as "pending" because the expected phrase was
        // absent. The answer must now come from the database, not from text.
        $service = $this->stubbedService();

        $this->assertSame([], $this->invokePrivate($service, 'pendingMigrations'));
        $this->assertSame(
            [],
            array_filter($service->commands, static fn (string $cmd): bool => str_contains($cmd, 'pretend')),
            'Pending migrations should no longer be probed by shelling out to migrate --pretend.'
        );
    }

    public function test_pending_migrations_are_named_in_the_failure_message(): void
    {
        $service = $this->stubbedService();

        $this->assertSame(
            '2026_01_01_000000_a, 2026_01_02_000000_b, 2026_01_03_000000_c and 2 more',
            $this->invokePrivate($service, 'describePending', [[
                '2026_01_01_000000_a',
                '2026_01_02_000000_b',
                '2026_01_03_000000_c',
                '2026_01_04_000000_d',
                '2026_01_05_000000_e',
            ]])
        );

        $this->assertSame(
            '2026_01_01_000000_a',
            $this->invokePrivate($service, 'describePending', [['2026_01_01_000000_a']])
        );
    }

    public function test_restore_points_are_listed_newest_first(): void
    {
        $restoreRoot = $this->tempDir('restore');

        foreach (['20260901-100000-aaaaaa', '20260910-120000-bbbbbb', '20260905-090000-cccccc'] as $id) {
            $this->writeFile(
                $restoreRoot . '/' . $id . '/manifest.json',
                (string) json_encode(['id' => $id, 'from' => 'x', 'changed' => [], 'added' => []])
            );
        }

        $points = $this->stubbedService(null, $restoreRoot)->restorePoints();

        $this->assertSame(
            ['20260910-120000-bbbbbb', '20260905-090000-cccccc', '20260901-100000-aaaaaa'],
            array_column($points, 'id')
        );
    }
}
