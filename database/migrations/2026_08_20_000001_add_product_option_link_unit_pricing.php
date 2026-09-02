<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-link unit pricing for continuous configurable option groups (slider,
 * number, quantity): the price of a single unit that the customer's chosen
 * value multiplies at checkout. Discrete groups keep per-value pricing on
 * product_option_link_value_pricing; the two never overlap per link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_link_pricing', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_option_group_product_id');
            $table->string('billing_cycle', 20);
            $table->decimal('price_modifier', 10, 2)->default(0);
            // Short explicit names: the Laravel defaults would exceed MySQL's 64-char identifier limit.
            $table->index('product_option_group_product_id', 'polp_pogp_id_idx');
            $table->foreign('product_option_group_product_id', 'polp_pogp_id_foreign')->references('id')->on('product_option_group_product')->cascadeOnDelete();
            $table->unique(['product_option_group_product_id', 'billing_cycle'], 'polp_pogp_id_billing_cycle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_link_pricing');
    }
};
