<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(EmailTemplateSeeder::class);
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

        // Permissions and roles have exactly one authority: AdminLteRbacSeeder.
        // This seeder used to carry a second copy of the inventory and sync() it
        // onto every role, so whichever of the two ran last silently overwrote
        // the other: the installer (rbac, then this) left `admin` holding 97 of
        // 103 permissions, while `db:seed` (this, then rbac) left it holding all
        // 103. Delegating keeps both entry points in agreement by construction.
        $this->call(AdminLteRbacSeeder::class);

        // AdminLteRbacSeeder promotes User::first(); bind the well-known default
        // administrator explicitly in case that is some other row.
        $adminUser = User::where('email', 'admin@localhost.com')->first();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminUser && $adminRole) {
            $adminUser->roles()->syncWithoutDetaching($adminRole);
        }
    }
}
