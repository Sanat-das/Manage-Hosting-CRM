<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the granular permission set the route files actually gate on
 * (permission:invoices.view, permission:hosting.manage, ...), plus the
 * panel roles AdminMiddleware accepts by default: admin, support, sales,
 * marketing (see App\Http\Middleware\AdminMiddleware).
 */
class AdminLteRbacSeeder extends Seeder
{
    public function run(): void
    {
        // --- Granular permission inventory (matches routes/*.php gates) ---
        $permissions = [
            // dashboard / cross-cutting
            'dashboard.view' => 'View Dashboard',
            'analytics.view' => 'View Analytics',
            'reports.view' => 'View Reports',
            'reports.export' => 'Export Reports',
            'activity.view' => 'View Activity Log',
            'search' => 'Use Global Search',

            // customers
            'customers.view' => 'View Customers',
            'customers.create' => 'Create Customers',
            'customers.edit' => 'Edit Customers',
            'customers.delete' => 'Delete Customers',

            // products
            'products.view' => 'View Products',
            'products.create' => 'Create Products',
            'products.edit' => 'Edit Products',
            'products.delete' => 'Delete Products',
            'products.groups' => 'Manage Product Groups',
            'products.options' => 'Manage Configurable Options',
            'products.addons' => 'Manage Addons',

            // orders
            'orders.view' => 'View Orders',
            'orders.create' => 'Create Orders',
            'orders.edit' => 'Edit Orders',

            // billing
            'invoices.view' => 'View Invoices',
            'invoices.create' => 'Create Invoices',
            'invoices.edit' => 'Edit Invoices',
            'invoices.delete' => 'Delete Invoices',
            'payments.view' => 'View Payments',
            'payments.create' => 'Record Payments',

            // hosting
            'hosting.view'         => 'View Hosting Services',
            'hosting.create'       => 'Create Hosting Services',
            'hosting.edit'         => 'Edit Hosting Services',
            'hosting.suspend'      => 'Suspend / Unsuspend Hosting Services',
            'hosting.delete'       => 'Delete Hosting Services',
            'hosting.manage'       => 'Manage Servers',
            'hosting.server_groups' => 'Manage Server Groups',

            // infrastructure — granular per sub-resource (enterprise/dns/inventory/provisioning)
            'datacenters.view' => 'View Datacenters',
            'datacenters.manage' => 'Manage Datacenters',
            'racks.view' => 'View Racks',
            'racks.manage' => 'Manage Racks',
            'ip-subnets.view' => 'View IP Subnets',
            'ip-subnets.manage' => 'Manage IP Subnets',
            'ip-addresses.view' => 'View IP Addresses',
            'ip-addresses.manage' => 'Manage IP Addresses',
            'vlans.view' => 'View VLANs',
            'vlans.manage' => 'Manage VLANs',
            'dns-zones.view' => 'View DNS Zones',
            'dns-zones.manage' => 'Manage DNS Zones',
            'dns-records.view' => 'View DNS Records',
            'dns-records.manage' => 'Manage DNS Records',
            'licenses.view' => 'View Licenses',
            'licenses.manage' => 'Manage Licenses',
            'catalog-products.view' => 'View Catalog Products',
            'catalog-products.manage' => 'Manage Catalog Products',
            'subscriptions.view' => 'View Subscriptions',
            'subscriptions.manage' => 'Manage Subscriptions',
            'usage-records.view' => 'View Usage Records',
            'usage-records.manage' => 'Manage Usage Records',
            'resource-types.view' => 'View Resource Types',
            'resource-types.manage' => 'Manage Resource Types',
            'resource-pools.view' => 'View Resource Pools',
            'resource-pools.manage' => 'Manage Resource Pools',
            'asset-relationships.view' => 'View Asset Relationships',
            'asset-relationships.manage' => 'Manage Asset Relationships',
            'inventory.view' => 'View Inventory',
            'inventory.manage' => 'Manage Inventory',
            'tax-rates.view' => 'View Tax Rates',
            'tax-rates.manage' => 'Manage Tax Rates',
            'product-bundles.view' => 'View Product Bundles',
            'product-bundles.manage' => 'Manage Product Bundles',
            'product-upgrades.view' => 'View Product Upgrades',
            'product-upgrades.manage' => 'Manage Product Upgrades',
            'service-instances.view' => 'View Service Instances',
            'service-instances.manage' => 'Manage Service Instances',
            'provisioning-events.view' => 'View Provisioning Events',
            'provisioning-events.manage' => 'Manage Provisioning Events',

            // domains / ssl / dns
            'domains.view' => 'View Domains',
            'domains.manage' => 'Manage Domains',

            // settings / config
            'settings.view' => 'View Settings',
            'settings.manage' => 'Manage Settings',
            'settings.edit' => 'Edit Settings',

            // scheduled tasks (admin Cron Jobs page)
            'cron.view' => 'View Cron Jobs',
            'cron.manage' => 'Manage Cron Jobs',

            // modules
            'modules.view' => 'View Modules',
            'modules.manage' => 'Manage Modules',

            // support
            'tickets.view' => 'View Tickets',
            'tickets.create' => 'Create Tickets',
            'tickets.edit' => 'Edit Tickets',
            'tickets.assign' => 'Assign Tickets',
            'tickets.transfer' => 'Transfer Tickets',
            'kb.view' => 'View Knowledge Base',
            'kb.create' => 'Create KB Articles',
            'kb.edit' => 'Edit KB Articles',
            'kb.delete' => 'Delete KB Articles',

            // users / email
            'users.view' => 'View Users',
            'users.create' => 'Create Users',
            'users.edit' => 'Edit Users',
            'users.delete' => 'Delete Users',
            'email.view' => 'View Email Log',
            'email.manage' => 'Manage Email',

            // roles / rbac
            'manage-roles' => 'Manage Roles & Permissions',
            'manage-users' => 'Manage Users',
            'notifications.view' => 'View Notifications',
            'notifications.manage' => 'Manage Notifications',
        ];

        foreach ($permissions as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }

        // --- Role → permission matrix ---
        $all = array_keys($permissions);

        $roles = [
            'admin' => [
                'label' => 'Administrator',
                'permissions' => $all,
            ],
            'support' => [
                'label' => 'Support Agent',
                'permissions' => [
                    'dashboard.view',
                    'activity.view',
                    'customers.view',
                    'invoices.view',
                    'payments.view',
                    'hosting.view',
                    'domains.view',
                    'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign', 'tickets.transfer',
                    'kb.view', 'kb.create', 'kb.edit',
                    'email.view',
                ],
            ],
            'sales' => [
                'label' => 'Sales',
                'permissions' => [
                    'dashboard.view',
                    'analytics.view',
                    'reports.view',
                    'customers.view', 'customers.create', 'customers.edit',
                    'products.view',
                    'orders.view', 'orders.create',
                    'invoices.view', 'invoices.create',
                    'payments.view', 'payments.create',
                    'hosting.view',
                    'domains.view',
                    'tickets.view', 'tickets.create',
                    'kb.view',
                ],
            ],
            'marketing' => [
                'label' => 'Marketing',
                'permissions' => [
                    'dashboard.view',
                    'analytics.view',
                    'reports.view',
                    'customers.view',
                    'products.view',
                    'kb.view', 'kb.create', 'kb.edit',
                    'email.view',
                ],
            ],
            // Legacy AdminLTE defaults (kept for compatibility)
            'editor' => [
                'label' => 'Editor',
                'permissions' => [
                    'dashboard.view',
                    'customers.view', 'customers.edit',
                    'products.view', 'products.edit',
                    'invoices.view',
                    'tickets.view', 'tickets.edit',
                    'kb.view', 'kb.edit',
                ],
            ],
            'viewer' => [
                'label' => 'Viewer',
                'permissions' => [
                    'dashboard.view',
                    'customers.view',
                    'products.view',
                    'invoices.view',
                    'hosting.view',
                    'domains.view',
                    'tickets.view',
                    'kb.view',
                    'reports.view',
                ],
            ],
        ];

        foreach ($roles as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $definition['label']]);

            $permissionIds = Permission::whereIn('name', $definition['permissions'])->pluck('id');
            $role->permissions()->sync($permissionIds);
        }

        // Promote the first user to admin if no user has a role yet.
        $user = User::first();

        if ($user !== null) {
            $admin = Role::where('name', 'admin')->first();

            if ($admin !== null && ! $user->hasRole('admin')) {
                $user->roles()->syncWithoutDetaching($admin);
            }
        }
    }
}
