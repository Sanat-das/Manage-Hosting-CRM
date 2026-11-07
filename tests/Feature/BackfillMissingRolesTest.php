<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026_11_07_000001_backfill_missing_roles: restores the staff / editor /
 * viewer adminlte_roles rows that existing installs never received (the
 * in-app updater runs migrate --force but never db:seed).
 *
 * The migration must create only the roles that are missing, attach the
 * seeder's permission sets to those roles only, and never overwrite an
 * existing role's label or re-sync its permissions.
 */
class BackfillMissingRolesTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_11_07_000001_backfill_missing_roles.php');

        $migration->up();
    }

    private function deleteRole(string $name): void
    {
        $roleId = DB::table('adminlte_roles')->where('name', $name)->value('id');

        DB::table('adminlte_permission_role')->where('role_id', $roleId)->delete();
        DB::table('adminlte_role_user')->where('role_id', $roleId)->delete();
        DB::table('adminlte_roles')->where('name', $name)->delete();
    }

    /** @return string[] sorted permission names for the role */
    private function permissionNames(string $role): array
    {
        $names = DB::table('adminlte_permission_role')
            ->join('adminlte_permissions', 'adminlte_permissions.id', '=', 'adminlte_permission_role.permission_id')
            ->join('adminlte_roles', 'adminlte_roles.id', '=', 'adminlte_permission_role.role_id')
            ->where('adminlte_roles.name', $role)
            ->pluck('adminlte_permissions.name')
            ->all();

        sort($names);

        return $names;
    }

    public function test_recreates_a_deleted_staff_role_with_the_seeder_permission_set(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $editorPermissionsBefore = $this->permissionNames('editor');
        $viewerPermissionsBefore = $this->permissionNames('viewer');

        $this->deleteRole('staff');

        $this->assertSame(0, DB::table('adminlte_roles')->where('name', 'staff')->count());

        $this->runMigration();

        $this->assertDatabaseHas('adminlte_roles', ['name' => 'staff', 'label' => 'Staff']);

        $this->assertSame(
            ['chat.create_channel', 'chat.view', 'customers.view', 'dashboard.view', 'domains.view', 'invoices.view', 'kb.view', 'products.view', 'search', 'tickets.view'],
            $this->permissionNames('staff'),
        );

        // Existing roles were untouched: same permission sets, no duplicates.
        $this->assertSame($editorPermissionsBefore, $this->permissionNames('editor'));
        $this->assertSame($viewerPermissionsBefore, $this->permissionNames('viewer'));
        $this->assertSame(1, DB::table('adminlte_roles')->where('name', 'editor')->count());
        $this->assertSame(1, DB::table('adminlte_roles')->where('name', 'viewer')->count());
    }

    public function test_rerun_is_idempotent_and_never_overwrites_edited_labels_or_permissions(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        $this->deleteRole('staff');
        $this->runMigration();

        // Per-install Roles-screen edits: renamed label + trimmed permissions.
        DB::table('adminlte_roles')->where('name', 'staff')->update(['label' => 'Staff (custom)']);
        $staffId = DB::table('adminlte_roles')->where('name', 'staff')->value('id');
        DB::table('adminlte_permission_role')->where('role_id', $staffId)->delete();
        $searchId = DB::table('adminlte_permissions')->where('name', 'search')->value('id');
        DB::table('adminlte_permission_role')->insert(['permission_id' => $searchId, 'role_id' => $staffId]);

        $this->runMigration();

        $this->assertDatabaseHas('adminlte_roles', ['name' => 'staff', 'label' => 'Staff (custom)']);
        $this->assertSame(['search'], $this->permissionNames('staff'));
        $this->assertSame(1, DB::table('adminlte_roles')->where('name', 'staff')->count());
    }

    public function test_notification_settings_page_renders_the_live_role_label(): void
    {
        $this->seed(AdminLteRbacSeeder::class);

        DB::table('adminlte_roles')
            ->where('name', 'support')
            ->update(['label' => 'Support Heroes Live 42']);

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('admin.notification-settings.index'))
            ->assertOk()
            ->assertSee('Support Heroes Live 42');
    }
}
