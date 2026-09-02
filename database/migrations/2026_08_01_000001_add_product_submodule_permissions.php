<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

/**
 * Add the three product sub-module permissions referenced by the admin
 * sidebar (config/adminlte.php) and the product module routes.
 *
 * The base products.* permissions (view/create/edit/delete) are seeded by
 * InitialDataSeeder, but `products.groups`, `products.options` and
 * `products.addons` are referenced by the sidebar contract and were NOT
 * present in the seeder — without them the Product Groups / Configurable
 * Options / Addons menu items never render and their routes 403 even for
 * admins. This migration backfills them idempotently and grants them to
 * every role that already holds `products.view`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'products.groups' => 'Manage Product Groups',
            'products.options' => 'Manage Configurable Options',
            'products.addons' => 'Manage Product Addons',
        ];

        $permissionIds = [];
        foreach ($names as $name => $label) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $label]);
            $permissionIds[] = $permission->id;
        }

        // Grant to every role that already has products.view (admin, sales, ...).
        $productViewerRoleIds = Role::whereHas('permissions', fn ($q) => $q->where('name', 'products.view'))
            ->pluck('id');

        foreach ($productViewerRoleIds as $roleId) {
            $role = Role::find($roleId);
            $role?->permissions()->syncWithoutDetaching($permissionIds);
        }
    }

    public function down(): void
    {
        foreach (['products.groups', 'products.options', 'products.addons'] as $name) {
            Permission::where('name', $name)->delete();
        }
    }
};
