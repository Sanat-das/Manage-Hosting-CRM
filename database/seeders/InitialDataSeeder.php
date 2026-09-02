<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::table('users')->where('email', 'admin@localhost.com')->exists()) {
            DB::table('users')->insert([
                'email' => 'admin@localhost.com',
                'password_hash' => Hash::make('Admin@123'),
                'role' => 'admin',
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $productGroups = [
            ['name' => 'Shared Hosting', 'slug' => 'shared-hosting', 'description' => 'Shared hosting plans with cPanel', 'sort_order' => 1, 'status' => 'active', 'is_hosting' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Reseller Hosting', 'slug' => 'reseller-hosting', 'description' => 'Reseller hosting plans', 'sort_order' => 2, 'status' => 'active', 'is_hosting' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'VPS Hosting', 'slug' => 'vps-hosting', 'description' => 'Virtual Private Server plans', 'sort_order' => 3, 'status' => 'active', 'is_hosting' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dedicated Servers', 'slug' => 'dedicated-servers', 'description' => 'Dedicated server plans', 'sort_order' => 4, 'status' => 'active', 'is_hosting' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Domain Registration', 'slug' => 'domain-registration', 'description' => 'Domain name registration and transfer', 'sort_order' => 5, 'status' => 'active', 'is_hosting' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Addons & Extras', 'slug' => 'addons-extras', 'description' => 'Product addons and extras', 'sort_order' => 6, 'status' => 'active', 'is_hosting' => false, 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($productGroups as $row) {
            DB::table('product_groups')->updateOrInsert(['slug' => $row['slug']], $row);
        }

        $serverGroups = [
            ['name' => 'Primary cPanel Servers', 'description' => 'Main cPanel/WHM server cluster', 'load_balancing' => 'round_robin', 'status' => 'active', 'created_at' => now()],
            ['name' => 'VPS Nodes', 'description' => 'Virtualizor VPS host nodes', 'load_balancing' => 'least_loaded', 'status' => 'active', 'created_at' => now()],
        ];

        foreach ($serverGroups as $row) {
            DB::table('server_groups')->updateOrInsert(['name' => $row['name']], $row);
        }

        DB::table('datacenters')->updateOrInsert(['code' => 'DC01'], [
            'name' => 'Primary DC',
            'code' => 'DC01',
            'city' => 'New York',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('racks')->updateOrInsert(['datacenter_id' => 1, 'name' => 'Rack A1'], [
            'datacenter_id' => 1,
            'name' => 'Rack A1',
            'u_height' => 42,
            'u_available' => 42,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Canonical inventory — mirrors AdminLteRbacSeeder (authority) to avoid divergent names.
        $permLabels = [
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

            // hosting / infrastructure
            'hosting.view' => 'View Hosting & Infrastructure',
            'hosting.manage' => 'Manage Hosting & Infrastructure',
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

        foreach ($permLabels as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }

        $adminPerms = array_keys($permLabels);
        $supportPerms = ['dashboard.view', 'activity.view', 'customers.view', 'invoices.view', 'payments.view', 'hosting.view', 'domains.view', 'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign', 'tickets.transfer', 'kb.view', 'kb.create', 'kb.edit', 'email.view'];
        $salesPerms = ['dashboard.view', 'analytics.view', 'reports.view', 'customers.view', 'customers.create', 'customers.edit', 'products.view', 'orders.view', 'orders.create', 'invoices.view', 'invoices.create', 'payments.view', 'payments.create', 'hosting.view', 'domains.view', 'tickets.view', 'tickets.create', 'kb.view'];
        $marketingPerms = ['dashboard.view', 'analytics.view', 'reports.view', 'customers.view', 'products.view', 'kb.view', 'kb.create', 'kb.edit', 'email.view'];

        $roleData = [
            'admin' => ['label' => 'Administrator', 'permissions' => $adminPerms],
            'support' => ['label' => 'Support Team', 'permissions' => $supportPerms],
            'sales' => ['label' => 'Sales Team', 'permissions' => $salesPerms],
            'marketing' => ['label' => 'Marketing Team', 'permissions' => $marketingPerms],
            'editor' => ['label' => 'Editor', 'permissions' => ['dashboard.view', 'customers.view', 'customers.edit', 'products.view', 'products.edit', 'invoices.view', 'tickets.view', 'tickets.edit', 'kb.view', 'kb.edit']],
            'viewer' => ['label' => 'Viewer', 'permissions' => ['dashboard.view', 'customers.view', 'products.view', 'invoices.view', 'hosting.view', 'domains.view', 'tickets.view', 'kb.view', 'reports.view']],
        ];

        foreach ($roleData as $name => $def) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $def['label']]);
            $permIds = Permission::whereIn('name', $def['permissions'])->pluck('id');
            $role->permissions()->sync($permIds);
        }

        $adminUser = User::where('email', 'admin@localhost.com')->first();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminUser && $adminRole) {
            $adminUser->roles()->syncWithoutDetaching($adminRole);
        }
    }
}
