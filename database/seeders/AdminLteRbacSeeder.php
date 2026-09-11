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
            'products.addons' => 'Manage Product Addons',

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

            // system & about
            'system.view' => 'View System & About',
            'system.update' => 'Perform System Updates',

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

        // Upsert rather than firstOrCreate: four migrations create permission
        // rows too, so for any name they share the first writer used to win the
        // label outright and this inventory's text was unreachable. Permissions
        // have no edit UI, so healing them here is safe. Roles below stay on
        // firstOrCreate for the opposite reason -- their labels ARE editable in
        // the Roles screen, and a re-seed must not overwrite that.
        //
        // One statement rather than 103 select-then-write pairs: the test suite
        // seeds this before every test, where the per-row version cost ~180s
        // across a full run.
        $now = now();

        Permission::upsert(
            array_map(
                static fn (string $name, string $label): array => [
                    'name' => $name,
                    'label' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_keys($permissions),
                array_values($permissions),
            ),
            ['name'],
            ['label', 'updated_at'],
        );

        // --- Role → permission matrix ---
        $all = array_keys($permissions);

        $roles = [
            'admin' => [
                'label' => 'Administrator',
                'permissions' => $all,
            ],
            'support' => [
                // Labels must match what is already deployed: this seeder is now
                // the only writer, and firstOrCreate() would otherwise hand fresh
                // installs different role names than every existing one.
                'label' => 'Support Team',
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
                'label' => 'Sales Team',
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
                'label' => 'Marketing Team',
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
            // `staff` is offered by StaffUserRequest and the Users form, and
            // UserController::syncAdminlteRoles() syncs the pivot to the role of
            // that name — so without a row here every staff account created
            // through the UI lands in the panel holding nothing at all. The set
            // is deliberately read-only; widen it per-install in the Roles UI.
            'staff' => [
                'label' => 'Staff',
                'permissions' => [
                    // Deliberately no hosting.view: it is the gate on
                    // admin.rdp-console.password and the SSH console, so it
                    // discloses stored server credentials. A generic read-only
                    // role should not carry that by default -- grant it per
                    // install in the Roles UI to the people who need it.
                    'dashboard.view',
                    'customers.view',
                    'products.view',
                    'invoices.view',
                    'domains.view',
                    'tickets.view',
                    'kb.view',
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
