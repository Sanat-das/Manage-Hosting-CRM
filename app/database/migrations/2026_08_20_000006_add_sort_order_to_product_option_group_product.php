<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('option_group_id');
        });

        // Sequence existing links by their current pivot id (insertion order),
        // so the column is meaningful before anyone reorders via the UI.
        $rows = DB::table('product_option_group_product')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get(['id', 'product_id']);

        $sequence = [];
        foreach ($rows as $row) {
            $sequence[$row->product_id] = ($sequence[$row->product_id] ?? 0) + 1;
            DB::table('product_option_group_product')
                ->where('id', $row->id)
                ->update(['sort_order' => $sequence[$row->product_id]]);
        }
    }

    public function down(): void
    {
        Schema::table('product_option_group_product', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};