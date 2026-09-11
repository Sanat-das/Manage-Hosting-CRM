<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the admin role so it holds every permission that exists.
 *
 * Until 3ec32086 the two seeders each owned a copy of the permission
 * inventory and sync()'d it, so the installer's order left `admin` holding 97
 * of 103 -- missing hosting.create/edit/suspend/delete and
 * system.view/update. That was invisible because hasPermission() returned
 * true for admins before reading a row.
 *
 * The seeder fix only reaches an installation that re-seeds, and the updater
 * runs `migrate --force` but never `db:seed`. So without this migration,
 * removing that short-circuit would drop every already-installed admin off 27
 * routes under /admin/system and /admin/hosting the moment they updated.
 *
 * Idempotent, and safe on a fresh install: migrations run before seeders, so
 * there is nothing to attach yet and the seeder does the work instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $adminRoleId = DB::table('adminlte_roles')->where('name', 'admin')->value('id');

        if ($adminRoleId === null) {
            return;
        }

        $held = DB::table('adminlte_permission_role')
            ->where('role_id', $adminRoleId)
            ->pluck('permission_id')
            ->all();

        $missing = DB::table('adminlte_permissions')
            ->when($held !== [], fn ($query) => $query->whereNotIn('id', $held))
            ->pluck('id');

        if ($missing->isEmpty()) {
            return;
        }

        DB::table('adminlte_permission_role')->insertOrIgnore(
            $missing->map(static fn (int $permissionId): array => [
                'permission_id' => $permissionId,
                'role_id' => $adminRoleId,
            ])->all()
        );
    }

    /**
     * Not reversed: this only ever adds rows the admin role is supposed to
     * have, and there is no record of which ones predated it. Removing them
     * would lock administrators out of the panel.
     */
    public function down(): void
    {
        //
    }
};
