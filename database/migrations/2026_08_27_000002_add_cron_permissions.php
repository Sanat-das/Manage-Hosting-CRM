<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Add the permissions gating the admin Cron Jobs page.
 *
 * Backfilled the same way as 2026_08_19_000005_add_module_permissions: grant
 * to every role that already holds the SYSTEM section gate (`settings.view`),
 * falling back to `products.view` if none exists. Without this an existing
 * installation would 403 on the new page even for admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'cron.view' => 'View Cron Jobs',
            'cron.manage' => 'Manage Cron Jobs',
        ];

        $permissionIds = [];
        foreach ($names as $name => $label) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $label]);
            $permissionIds[] = $permission->id;
        }

        $gate = Role::whereHas('permissions', fn ($q) => $q->where('name', 'settings.view'))->exists()
            ? 'settings.view'
            : 'products.view';

        $roleIds = Role::whereHas('permissions', fn ($q) => $q->where('name', $gate))->pluck('id');

        foreach ($roleIds as $roleId) {
            Role::find($roleId)?->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        foreach (['cron.view', 'cron.manage'] as $name) {
            Permission::where('name', $name)->delete();
        }
    }
};
