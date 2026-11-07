<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the adminlte_roles rows for staff / editor / viewer.
 *
 * AdminLteRbacSeeder is the only writer of these roles, but the in-app
 * updater runs `php artisan migrate --force` and NEVER `db:seed`, so every
 * already-installed system still lacks these three roles while
 * UserController::syncAdminlteRoles() and the Users form reference `staff`
 * by name. Creating the rows here restores what fresh installs get from the
 * seeder.
 *
 * Same shape as 2026_09_12_000007_backfill_chat_permissions.php:
 * idempotent, guarded on the tables existing, a no-op on a fresh install
 * because migrations run before seeders and the seeder's firstOrCreate +
 * sync() will simply reconcile afterwards.
 *
 * Crucially, `insertOrIgnore` on adminlte_roles means an existing row's
 * edited label is never overwritten, and permissions are attached only to
 * roles that did not exist before this run: re-running must never re-sync an
 * existing role's permission set, which would wipe per-install Roles-screen
 * edits. A role whose row exists but whose pivot links are broken is the
 * installation's business, not this migration's.
 */
return new class extends Migration
{
    /**
     * Role name => the label the seeder assigns on fresh installs.
     */
    private const ROLES = [
        'staff' => 'Staff',
        'editor' => 'Editor',
        'viewer' => 'Viewer',
    ];

    /**
     * Permission labels, copied verbatim from AdminLteRbacSeeder so a missing
     * permission row is healed with the text the seeder would have used.
     */
    private const PERMISSION_LABELS = [
        'dashboard.view' => 'View Dashboard',
        'search' => 'Use Global Search',
        'customers.view' => 'View Customers',
        'customers.edit' => 'Edit Customers',
        'products.view' => 'View Products',
        'products.edit' => 'Edit Products',
        'invoices.view' => 'View Invoices',
        'hosting.view' => 'View Hosting Services',
        'domains.view' => 'View Domains',
        'reports.view' => 'View Reports',
        'tickets.view' => 'View Tickets',
        'tickets.edit' => 'Edit Tickets',
        'kb.view' => 'View Knowledge Base',
        'kb.edit' => 'Edit KB Articles',
        'chat.view' => 'View Live Chat',
        'chat.create_channel' => 'Create Chat Channels',
    ];

    /**
     * Role name => permission names, copied verbatim from
     * AdminLteRbacSeeder's staff / editor / viewer definitions.
     */
    private const ROLE_PERMISSIONS = [
        'staff' => [
            'dashboard.view',
            'search',
            'customers.view',
            'products.view',
            'invoices.view',
            'domains.view',
            'tickets.view',
            'kb.view',
            'chat.view',
            'chat.create_channel',
        ],
        'editor' => [
            'dashboard.view',
            'search',
            'customers.view',
            'customers.edit',
            'products.view',
            'products.edit',
            'invoices.view',
            'tickets.view',
            'tickets.edit',
            'kb.view',
            'kb.edit',
        ],
        'viewer' => [
            'dashboard.view',
            'search',
            'customers.view',
            'products.view',
            'invoices.view',
            'hosting.view',
            'domains.view',
            'tickets.view',
            'kb.view',
            'reports.view',
        ],
    ];

    public function up(): void
    {
        foreach (['adminlte_roles', 'adminlte_permissions', 'adminlte_permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        $now = now();

        // Roles that already exist on this install keep their label (and
        // permission set) exactly as the admin left it in the Roles screen.
        $existing = DB::table('adminlte_roles')
            ->whereIn('name', array_keys(self::ROLES))
            ->pluck('name')
            ->all();

        DB::table('adminlte_roles')->insertOrIgnore(
            array_map(
                static fn (string $name, string $label): array => [
                    'name' => $name,
                    'label' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys(self::ROLES),
                array_values(self::ROLES),
            )
        );

        $missing = array_diff(array_keys(self::ROLES), $existing);

        if ($missing === []) {
            return;
        }

        DB::table('adminlte_permissions')->insertOrIgnore(
            array_map(
                static fn (string $name): array => [
                    'name' => $name,
                    'label' => self::PERMISSION_LABELS[$name],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys(self::PERMISSION_LABELS),
            )
        );

        $permissionIds = DB::table('adminlte_permissions')
            ->whereIn('name', array_keys(self::PERMISSION_LABELS))
            ->pluck('id', 'name');

        $roleIds = DB::table('adminlte_roles')
            ->whereIn('name', $missing)
            ->pluck('id', 'name');

        $pivot = [];

        foreach ($missing as $role) {
            foreach (self::ROLE_PERMISSIONS[$role] as $permission) {
                if (isset($permissionIds[$permission], $roleIds[$role])) {
                    $pivot[] = ['permission_id' => $permissionIds[$permission], 'role_id' => $roleIds[$role]];
                }
            }
        }

        if ($pivot !== []) {
            DB::table('adminlte_permission_role')->insertOrIgnore($pivot);
        }
    }

    /**
     * Not reversed: this only ever adds role rows the seeder would have
     * created and the pivot links that are supposed to exist, and there is no
     * record of which predated it. Removing them would hand staff accounts
     * created through the UI an empty role on a rollback that was meant to
     * be schema-only. Same rationale as
     * 2026_09_12_000007_backfill_chat_permissions.php.
     */
    public function down(): void
    {
        //
    }
};
