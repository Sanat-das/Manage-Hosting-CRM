<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * The chat.* permissions: that the seeder declares them, that the backfill
 * migration puts them back on an install that only ever runs `migrate`, and
 * that the re-gated Live Chat route actually enforces chat.view.
 */
class ChatPermissionTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    private const CHAT_PERMISSIONS = ['chat.view', 'chat.manage', 'chat.create_channel'];

    public function test_seeder_declares_the_three_chat_permissions(): void
    {
        foreach (self::CHAT_PERMISSIONS as $name) {
            $this->assertTrue(
                Permission::where('name', $name)->exists(),
                "AdminLteRbacSeeder did not create the {$name} permission.",
            );
        }
    }

    public function test_admin_holds_every_chat_permission_and_still_holds_everything(): void
    {
        $admin = $this->adminUser();

        foreach (self::CHAT_PERMISSIONS as $name) {
            $this->assertTrue($admin->hasPermission($name), "admin is missing {$name}.");
        }

        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $this->assertSame(
            Permission::count(),
            $adminRole->permissions()->count(),
            'The admin role no longer holds every permission after the chat additions.',
        );
    }

    public function test_staff_roles_get_view_and_create_but_not_manage(): void
    {
        foreach (['support', 'sales', 'staff'] as $roleName) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $held = $role->permissions()->pluck('name');

            $this->assertContains('chat.view', $held, "{$roleName} is missing chat.view.");
            $this->assertContains('chat.create_channel', $held, "{$roleName} is missing chat.create_channel.");
            $this->assertNotContains('chat.manage', $held, "{$roleName} must not hold chat.manage.");
        }

        // marketing is the baseline "holds nothing relevant" role the test
        // helpers depend on — it must stay clear of chat.* entirely.
        $marketing = Role::where('name', 'marketing')->firstOrFail()->permissions()->pluck('name');

        foreach (self::CHAT_PERMISSIONS as $name) {
            $this->assertNotContains($name, $marketing);
        }
    }

    /**
     * The upgrade path. The in-app updater runs `migrate --force` and never
     * `db:seed`, so the seeder alone would leave every existing install with
     * no chat.* rows and no Live Chat menu.
     */
    public function test_backfill_migration_restores_chat_permissions_without_seeding(): void
    {
        // Simulate a database that predates the chat feature.
        Permission::whereIn('name', self::CHAT_PERMISSIONS)->delete();

        $this->assertSame(0, Permission::whereIn('name', self::CHAT_PERMISSIONS)->count());
        $this->assertSame(
            0,
            DB::table('adminlte_permission_role')
                ->join('adminlte_permissions', 'adminlte_permissions.id', '=', 'adminlte_permission_role.permission_id')
                ->whereIn('adminlte_permissions.name', self::CHAT_PERMISSIONS)
                ->count(),
            'Deleting the permissions should have cascaded their pivot rows away.',
        );

        $this->runBackfillMigration();

        $admin = $this->adminUser();

        foreach (self::CHAT_PERMISSIONS as $name) {
            $this->assertTrue($admin->hasPermission($name), "migrate alone did not restore {$name} for admin.");
        }

        $support = Role::where('name', 'support')->firstOrFail()->permissions()->pluck('name');
        $this->assertContains('chat.view', $support);
        $this->assertContains('chat.create_channel', $support);
        $this->assertNotContains('chat.manage', $support);
    }

    public function test_backfill_migration_is_idempotent(): void
    {
        $before = [
            'permissions' => Permission::count(),
            'pivot' => DB::table('adminlte_permission_role')->count(),
        ];

        $this->runBackfillMigration();
        $this->runBackfillMigration();

        $this->assertSame($before['permissions'], Permission::count());
        $this->assertSame($before['pivot'], DB::table('adminlte_permission_role')->count());
    }

    public function test_chat_index_route_is_gated_on_chat_view(): void
    {
        $allowed = $this->panelUserWithPermissions('chat.view');

        $this->actingAs($allowed)
            ->get(route('admin.chat.index'))
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $denied = $this->panelUserWithoutPermission('chat.view');

        $this->actingAs($denied)
            ->get(route('admin.chat.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_the_admin_login(): void
    {
        $this->get(route('admin.chat.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_tickets_view_alone_no_longer_opens_the_chat(): void
    {
        // The route used to be gated on tickets.view. Proving the gate really
        // moved is the whole point of the re-gating.
        $ticketsOnly = $this->panelUserWithPermissions('tickets.view');

        $this->actingAs($ticketsOnly)
            ->get(route('admin.chat.index'))
            ->assertForbidden();
    }

    /**
     * Run the backfill migration's up() directly — RefreshDatabase has already
     * migrated, so this is the only way to exercise it against a database that
     * has had its chat rows removed.
     */
    private function runBackfillMigration(): void
    {
        $migration = require database_path('migrations/2026_09_12_000007_backfill_chat_permissions.php');

        $migration->up();
    }
}
