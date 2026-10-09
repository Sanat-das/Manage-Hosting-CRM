<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the activation of the Logs-hub permissions introduced with the
 * audit/activity + email-log centralization (2026-10-09).
 *
 * AdminLteRbacSeeder declares all four for fresh installs; the in-app updater
 * runs `migrate --force` and never `db:seed`, so the backfill migration must
 * grant them to the admin role on existing installs — idempotently.
 */
final class LogsPermissionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const BACKFILL_MIGRATION = 'migrations/2026_10_09_000005_backfill_logs_permissions.php';

    /** @var list<string> */
    private const PERMISSIONS = ['audit.view', 'module_logs.view', 'domain_logs.view', 'consent.view'];

    private function runBackfillMigration(): void
    {
        $path = database_path(self::BACKFILL_MIGRATION);

        $this->assertFileExists($path, 'The logs-permission backfill migration is missing.');

        /** @var Migration $migration */
        $migration = require $path;

        $migration->up();
    }

    public function test_seeder_grants_all_four_logs_permissions_to_admin(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $admin = Role::where('name', 'admin')->first();

        $this->assertNotNull($admin, 'Role [admin] was not seeded.');

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "Role [admin] must hold [{$permission}] after seeding."
            );
        }
    }

    public function test_seeder_does_not_grant_them_to_support(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $support = Role::where('name', 'support')->first();

        $this->assertNotNull($support, 'Role [support] was not seeded.');

        foreach (self::PERMISSIONS as $permission) {
            $this->assertFalse(
                $support->hasPermission($permission),
                "Role [support] must not hold [{$permission}] by default (grant per-install in the Roles UI)."
            );
        }
    }

    public function test_backfill_migration_grants_the_permissions_to_admin_idempotently(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        // Simulate a pre-backfill install: the role matrix exists but the four
        // permissions and their pivot rows are absent.
        $ids = Permission::whereIn('name', self::PERMISSIONS)->pluck('id');
        DB::table('adminlte_permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('adminlte_permissions')->whereIn('name', self::PERMISSIONS)->delete();

        $this->assertSame(0, Permission::whereIn('name', self::PERMISSIONS)->count(), 'Precondition: permissions removed.');

        $this->runBackfillMigration();

        $this->assertSame(
            4,
            Permission::whereIn('name', self::PERMISSIONS)->count(),
            'The migration must create exactly one row per permission.'
        );

        $admin = Role::where('name', 'admin')->first();

        $this->assertNotNull($admin);

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue(
                $admin->hasPermission($permission),
                "Role [admin] must hold [{$permission}] after the backfill."
            );
        }

        // Idempotent: replaying must not duplicate permission or pivot rows.
        $this->runBackfillMigration();

        $this->assertSame(4, Permission::whereIn('name', self::PERMISSIONS)->count());

        $this->assertSame(
            4,
            DB::table('adminlte_permission_role')
                ->whereIn('permission_id', Permission::whereIn('name', self::PERMISSIONS)->pluck('id'))
                ->count()
        );
    }
}
