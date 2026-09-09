<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a catalog option value declare itself the default.
 *
 * The link snapshot already has `is_default` (it is what a FIXED option
 * resolves to — OptionPricingResolver::defaultValue), but the catalog had no
 * way to say which value that should be: ProductOptionLinkService blindly
 * flagged the first row it copied. An admin who wanted "8 GB" to be the
 * bundled default had to reorder the list to put it first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('label');
        });

        // Existing groups behaved as "first value in sort order is the
        // default" (ProductOptionLinkService::copyGroupValues flagged index 0
        // of a sort_order-ordered list); preserve exactly that, ties broken by
        // id as they were.
        $firstIds = DB::table('product_option_values')
            ->orderBy('option_group_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'option_group_id'])
            ->unique('option_group_id')
            ->pluck('id')
            ->all();

        if ($firstIds !== []) {
            DB::table('product_option_values')->whereIn('id', $firstIds)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('product_option_values', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
