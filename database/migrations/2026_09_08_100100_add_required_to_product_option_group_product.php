<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a customer-editable option must be answered on the order form.
 *
 * OptionSelectionRules hardcoded `required` for all seven input types, so an
 * optional add-on was impossible to express: a checkbox group rejected an
 * empty array and every dropdown needed a filler "None" value. Defaulting the
 * new column to true keeps every existing link behaving exactly as it does
 * today; a link opts out per product.
 *
 * Only meaningful for customer-editable links - a fixed option is not answered
 * by anyone, it is declared by the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->boolean('required')->default(true)->after('customer_editable');
        });
    }

    public function down(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->dropColumn('required');
        });
    }
};
