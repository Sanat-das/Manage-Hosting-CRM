<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
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

        DB::table('product_groups')->insert([
            ['name' => 'Shared Hosting', 'slug' => 'shared-hosting', 'description' => 'Shared hosting plans with cPanel', 'sort_order' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Reseller Hosting', 'slug' => 'reseller-hosting', 'description' => 'Reseller hosting plans', 'sort_order' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'VPS Hosting', 'slug' => 'vps-hosting', 'description' => 'Virtual Private Server plans', 'sort_order' => 3, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Dedicated Servers', 'slug' => 'dedicated-servers', 'description' => 'Dedicated server plans', 'sort_order' => 4, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Domain Registration', 'slug' => 'domain-registration', 'description' => 'Domain name registration and transfer', 'sort_order' => 5, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Addons & Extras', 'slug' => 'addons-extras', 'description' => 'Product addons and extras', 'sort_order' => 6, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('server_groups')->insert([
            ['name' => 'Primary cPanel Servers', 'description' => 'Main cPanel/WHM server cluster', 'load_balancing' => 'round_robin', 'status' => 'active', 'created_at' => now()],
            ['name' => 'VPS Nodes', 'description' => 'Virtualizor VPS host nodes', 'load_balancing' => 'least_loaded', 'status' => 'active', 'created_at' => now()],
        ]);

        DB::table('datacenters')->insert([
            'name' => 'Primary DC',
            'code' => 'DC01',
            'city' => 'New York',
            'country' => 'US',
            'timezone' => 'America/New_York',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('racks')->insert([
            'datacenter_id' => 1,
            'name' => 'Rack A1',
            'u_height' => 42,
            'u_available' => 42,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $permLabels = [
            'dashboard.view' => 'View Dashboard',
            'customers.view' => 'View Customers',
            'customers.create' => 'Create Customers',
            'customers.edit' => 'Edit Customers',
            'customers.delete' => 'Delete Customers',
            'products.view' => 'View Products',
            'products.create' => 'Create Products',
            'products.edit' => 'Edit Products',
            'products.delete' => 'Delete Products',
            'orders.view' => 'View Orders',
            'orders.create' => 'Create Orders',
            'orders.edit' => 'Edit Orders',
            'orders.delete' => 'Delete Orders',
            'invoices.view' => 'View Invoices',
            'invoices.create' => 'Create Invoices',
            'invoices.edit' => 'Edit Invoices',
            'invoices.delete' => 'Delete Invoices',
            'payments.view' => 'View Payments',
            'payments.create' => 'Record Payments',
            'hosting.view' => 'View Hosting Accounts',
            'hosting.manage' => 'Manage Hosting Accounts',
            'domains.view' => 'View Domains',
            'domains.manage' => 'Manage Domains',
            'tickets.view' => 'View Tickets',
            'tickets.create' => 'Create Tickets',
            'tickets.edit' => 'Edit Tickets',
            'tickets.assign' => 'Assign Tickets',
            'kb.view' => 'View KB Articles',
            'kb.create' => 'Create KB Articles',
            'kb.edit' => 'Edit KB Articles',
            'kb.delete' => 'Delete KB Articles',
            'analytics.view' => 'View Analytics',
            'reports.view' => 'View Reports',
            'reports.export' => 'Export Reports',
            'settings.view' => 'View Settings',
            'settings.edit' => 'Edit Settings',
            'users.view' => 'View Staff Accounts',
            'users.create' => 'Create Staff Accounts',
            'users.edit' => 'Edit Staff Accounts',
            'users.delete' => 'Delete Staff Accounts',
            'manage-roles' => 'Manage Roles & Permissions',
        ];

        foreach ($permLabels as $name => $label) {
            Permission::firstOrCreate(['name' => $name], ['label' => $label]);
        }

        $adminPerms = array_keys($permLabels);
        $supportPerms = ['dashboard.view', 'customers.view', 'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.assign', 'kb.view', 'kb.create', 'kb.edit', 'hosting.view', 'domains.view'];
        $salesPerms = ['dashboard.view', 'customers.view', 'customers.create', 'customers.edit', 'products.view', 'orders.view', 'orders.create', 'orders.edit', 'invoices.view', 'invoices.create', 'invoices.edit', 'payments.view', 'payments.create', 'hosting.view', 'domains.view'];
        $marketingPerms = ['dashboard.view', 'customers.view', 'products.view', 'products.create', 'products.edit', 'analytics.view', 'reports.view', 'reports.export', 'kb.view', 'kb.create', 'kb.edit', 'invoices.view'];

        $roleData = [
            'admin' => ['label' => 'Administrator', 'permissions' => $adminPerms],
            'support' => ['label' => 'Support Team', 'permissions' => $supportPerms],
            'sales' => ['label' => 'Sales Team', 'permissions' => $salesPerms],
            'marketing' => ['label' => 'Marketing Team', 'permissions' => $marketingPerms],
        ];

        foreach ($roleData as $name => $def) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $def['label']]);
            $permIds = Permission::whereIn('name', $def['permissions'])->pluck('id');
            $role->permissions()->syncWithoutDetaching($permIds);
        }

        $adminUser = \App\Models\User::where('email', 'admin@localhost.com')->first();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminUser && $adminRole) {
            $adminUser->roles()->syncWithoutDetaching($adminRole);
        }
    }
}
