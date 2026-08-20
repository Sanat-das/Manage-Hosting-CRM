<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product overrides for a linked option group's input constraints.
 *
 * `product_option_group_product` gains nullable input_min / input_max /
 * input_step / input_placeholder columns: a null value means the product
 * inherits the catalog group's value (product_option_groups.input_*), while a
 * set value overrides it for this product only. Continuous groups (slider /
 * number / quantity) and free-text groups use these on the order form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->decimal('input_min', 10, 2)->nullable()->after('customer_editable');
            $table->decimal('input_max', 10, 2)->nullable()->after('input_min');
            $table->decimal('input_step', 10, 2)->nullable()->after('input_max');
            $table->string('input_placeholder', 255)->nullable()->after('input_step');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->dropColumn(['input_min', 'input_max', 'input_step', 'input_placeholder']);
        });
    }
};
