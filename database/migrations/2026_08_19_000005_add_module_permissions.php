<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Add the module permissions referenced by the admin sidebar and the module
 * routes.
 *
 * The base permissions are seeded by InitialDataSeeder, but `modules.view`
 * and `modules.manage` are new — without them module routes 403 even for
 * admins. This migration backfills them idempotently and grants them to
 * every role that already holds the SYSTEM section gate permission
 * (`settings.view`), falling back to `products.view` if none exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'modules.view' => 'View Modules',
            'modules.manage' => 'Manage Modules',
        ];

        $permissionIds = [];
        foreach ($names as $name => $label) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $label]);
            $permissionIds[] = $permission->id;
        }

        // Grant to every role that already has the SYSTEM section gate
        // (settings.view), falling back to products.view if none exists.
        $gate = Role::whereHas('permissions', fn ($q) => $q->where('name', 'settings.view'))->exists()
            ? 'settings.view'
            : 'products.view';

        $moduleRoleIds = Role::whereHas('permissions', fn ($q) => $q->where('name', $gate))
            ->pluck('id');

        foreach ($moduleRoleIds as $roleId) {
            $role = Role::find($roleId);
            $role?->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        foreach (['modules.view', 'modules.manage'] as $name) {
            Permission::where('name', $name)->delete();
        }
    }
};
