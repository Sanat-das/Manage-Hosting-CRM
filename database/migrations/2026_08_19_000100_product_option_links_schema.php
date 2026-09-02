<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the option-link schema for the product options snapshot.
 *
 * - product_option_group_product.customer_editable: whether the customer may
 *   edit this option group's value on the order form.
 * - product_option_link_values: the concrete values offered for a linked
 *   option group on a product (mirrors product_option_values, but scoped to
 *   the pivot row instead of the group).
 * - product_option_link_value_pricing: per-billing-cycle price modifiers for
 *   each link value (mirrors product_option_pricing).
 * - order_items.config_options: JSON snapshot of the selected option values
 *   captured at order time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->boolean('customer_editable')->default(false);
        });

        Schema::create('product_option_link_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_option_group_product_id');
            $table->string('label', 100);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            // Short explicit names: the Laravel defaults would exceed MySQL's 64-char identifier limit.
            $table->index('product_option_group_product_id', 'polv_pogp_id_idx');
            $table->foreign('product_option_group_product_id', 'polv_pogp_id_foreign')->references('id')->on('product_option_group_product')->cascadeOnDelete();
        });

        Schema::create('product_option_link_value_pricing', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_option_link_value_id');
            $table->string('billing_cycle', 20);
            $table->decimal('price_modifier', 10, 2)->default(0);
            $table->index('product_option_link_value_id', 'polvp_polv_id_idx');
            $table->foreign('product_option_link_value_id', 'polvp_polv_id_foreign')->references('id')->on('product_option_link_values')->cascadeOnDelete();
            $table->unique(['product_option_link_value_id', 'billing_cycle'], 'polvp_polv_id_billing_cycle_unique');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('config_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('config_options');
        });

        Schema::dropIfExists('product_option_link_value_pricing');
        Schema::dropIfExists('product_option_link_values');

        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->dropColumn('customer_editable');
        });
    }
};
