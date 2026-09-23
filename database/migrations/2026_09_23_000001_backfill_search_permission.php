<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the dormant `search` permission and attach it to the roles that can
 * already reach the search surface.
 *
 * AdminLteRbacSeeder is the single authority for the permission inventory and
 * now grants `search` to the panel roles, but the in-app updater runs
 * `php artisan migrate --force` and NEVER `db:seed`. The global search rollout
 * gates `/admin/search` (and the typeahead endpoint) on `permission:search`
 * instead of the old `dashboard.view`, so on every already installed system the
 * seeder alone would leave zero `search` pivot rows and the feature would
 * simply vanish — for administrators too, since the 2026-09-11 fix removed the
 * admin short-circuit and an admin's access now comes from its own pivot rows.
 *
 * The grant set is derived, not hardcoded: every role that holds `dashboard.view`
 * today held the old gate, so it keeps exactly the access it has now. A role
 * without `dashboard.view` (e.g. `client`) is deliberately skipped.
 *
 * Same shape as 2026_09_12_000007_backfill_chat_permissions.php: idempotent,
 * guarded on the tables existing, and a no-op on a fresh install because
 * migrations run before seeders and there is nothing to attach yet.
 */
return new class extends Migration
{
    /** The permission this migration activates. */
    private const PERMISSION = 'search';

    /** Label must match AdminLteRbacSeeder::$permissions. */
    private const LABEL = 'Use Global Search';

    /** Roles holding this permission are the ones that gain `search`. */
    private const SOURCE_PERMISSION = 'dashboard.view';

    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $now = now();

        DB::table('adminlte_permissions')->insertOrIgnore([
            'name' => self::PERMISSION,
            'label' => self::LABEL,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $permissionId = DB::table('adminlte_permissions')->where('name', self::PERMISSION)->value('id');
        $sourcePermissionId = DB::table('adminlte_permissions')->where('name', self::SOURCE_PERMISSION)->value('id');

        if ($permissionId === null || $sourcePermissionId === null) {
            return;
        }

        $roleIds = DB::table('adminlte_permission_role')
            ->where('permission_id', $sourcePermissionId)
            ->pluck('role_id');

        $pivot = $roleIds->map(static fn ($roleId): array => [
            'permission_id' => $permissionId,
            'role_id' => (int) $roleId,
        ])->all();

        if ($pivot !== []) {
            DB::table('adminlte_permission_role')->insertOrIgnore($pivot);
        }
    }

    /**
     * Not reversed: this only ever adds the permission row and the pivot links
     * that are supposed to exist, and there is no record of which predated it.
     * Removing them would take the search surface away from every panel role on
     * a rollback that was meant to be schema-only. Same rationale as the
     * 2026-09-11 admin backfill and the 2026-09-12 chat backfill.
     */
    public function down(): void
    {
        //
    }
};
