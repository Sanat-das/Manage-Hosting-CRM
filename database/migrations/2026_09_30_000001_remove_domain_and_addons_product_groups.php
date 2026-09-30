<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove the "Domain Registration" and "Addons & Extras" product groups and
 * every product belonging to them.
 *
 * The default install no longer seeds these groups. Live databases (and the
 * local dev DB) still carry their rows, so this migration converges them.
 * `products.product_group_id` is nullable (no FK), so products are deleted
 * first, then the groups by slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->whereIn('product_group_id', DB::table('product_groups')
                ->whereIn('slug', ['domain-registration', 'addons-extras'])
                ->pluck('id'))
            ->delete();

        DB::table('product_groups')
            ->whereIn('slug', ['domain-registration', 'addons-extras'])
            ->delete();
    }

    public function down(): void
    {
        $groups = [
            [
                'name' => 'Domain Registration',
                'slug' => 'domain-registration',
                'description' => 'Domain name registration and transfer',
                'sort_order' => 5,
                'status' => 'active',
                'is_hosting' => false,
            ],
            [
                'name' => 'Addons & Extras',
                'slug' => 'addons-extras',
                'description' => 'Product addons and extras',
                'sort_order' => 6,
                'status' => 'active',
                'is_hosting' => false,
            ],
        ];

        foreach ($groups as $group) {
            $exists = DB::table('product_groups')
                ->where('slug', $group['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('product_groups')->insert([
                'name' => $group['name'],
                'slug' => $group['slug'],
                'description' => $group['description'],
                'sort_order' => $group['sort_order'],
                'status' => $group['status'],
                'is_hosting' => $group['is_hosting'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $groupId = DB::table('product_groups')->where('slug', 'domain-registration')->value('id');

        if ($groupId === null) {
            return;
        }

        $exists = DB::table('products')
            ->where('name', 'Domain Registration')
            ->where('product_group_id', $groupId)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('products')->insert([
            'name' => 'Domain Registration',
            'product_group_id' => $groupId,
            'description' => 'Domain name registration. Pricing is resolved from the domain pricing tables at order time.',
            'price' => 0,
            'billing_cycle' => 'annual',
            'payment_type' => 'recurring',
            'setup_fee' => 0,
            'provisioning_module' => 'manual',
            'require_domain' => true,
            'show_in_order' => false,
            'show_in_affiliate' => false,
            'only_admin' => false,
            'sort_order' => 60,
            'status' => 'active',
            'quantity_behaviour' => 'none',
            'recurring_cycles_limit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
