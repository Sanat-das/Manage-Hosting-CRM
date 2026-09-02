<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure a "Domain Registration" product exists for client-side domain
 * purchases.
 *
 * `orders.product_id` is NOT NULL (no FK), so a domain registration order
 * needs a real product row to link to. The product carries no price of its
 * own — registration pricing is resolved from `domain_pricing` /
 * `domain_pricing_terms` at order time via DomainService.
 */
return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            DB::table('products')->where('name', 'Domain Registration')->delete();
        });
    }
};
