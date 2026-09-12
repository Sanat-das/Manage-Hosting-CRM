<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the three chat.* permissions and attach them to the roles that
 * should hold them.
 *
 * AdminLteRbacSeeder is the single authority for the permission inventory, but
 * the in-app updater runs `php artisan migrate --force` and NEVER `db:seed`.
 * The Live Chat menu item and the /admin/chat routes move from
 * `tickets.view` to `chat.view` in this same change, so on every already
 * installed system the seeder alone would leave zero chat.* rows and the
 * feature would simply vanish — for administrators too, since the 2026-09-11
 * fix removed the admin short-circuit and an admin's access now comes from its
 * pivot rows.
 *
 * Same shape as 2026_09_11_000001_grant_admin_role_every_permission.php:
 * idempotent, guarded on the tables existing, and a no-op on a fresh install
 * because migrations run before seeders and there is nothing to attach yet.
 */
return new class extends Migration
{
    /**
     * Permission name => the roles that receive it.
     *
     * `admin` is listed explicitly rather than relying on "every permission",
     * so this migration stays correct regardless of the order it lands in
     * relative to the grant-admin-everything backfill.
     */
    private const GRANTS = [
        'chat.view' => ['admin', 'support', 'sales', 'staff'],
        'chat.manage' => ['admin'],
        'chat.create_channel' => ['admin', 'support', 'sales', 'staff'],
    ];

    private const LABELS = [
        'chat.view' => 'View Live Chat',
        'chat.manage' => 'Manage Live Chat',
        'chat.create_channel' => 'Create Chat Channels',
    ];

    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $now = now();

        DB::table('adminlte_permissions')->insertOrIgnore(
            array_map(
                static fn (string $name): array => [
                    'name' => $name,
                    'label' => self::LABELS[$name],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys(self::GRANTS),
            )
        );

        $permissionIds = DB::table('adminlte_permissions')
            ->whereIn('name', array_keys(self::GRANTS))
            ->pluck('id', 'name');

        $roleIds = DB::table('adminlte_roles')
            ->whereIn('name', ['admin', 'support', 'sales', 'staff'])
            ->pluck('id', 'name');

        $pivot = [];

        foreach (self::GRANTS as $permission => $roles) {
            $permissionId = $permissionIds[$permission] ?? null;

            if ($permissionId === null) {
                continue;
            }

            foreach ($roles as $role) {
                // A role that does not exist on this install is skipped, not
                // created: which roles exist is the installation's business.
                if (isset($roleIds[$role])) {
                    $pivot[] = ['permission_id' => $permissionId, 'role_id' => $roleIds[$role]];
                }
            }
        }

        if ($pivot !== []) {
            DB::table('adminlte_permission_role')->insertOrIgnore($pivot);
        }
    }

    /**
     * Not reversed: this only ever adds permission rows and the pivot links
     * that are supposed to exist, and there is no record of which predated it.
     * Removing them would take the Live Chat menu away from administrators on
     * a rollback that was meant to be schema-only. Same rationale as the
     * 2026-09-11 admin backfill.
     */
    public function down(): void
    {
        //
    }
};
