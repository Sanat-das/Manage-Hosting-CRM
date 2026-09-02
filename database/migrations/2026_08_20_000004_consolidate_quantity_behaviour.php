<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidate single-unit ordering onto quantity_behaviour.
 *
 * The "Sell as a single unit only" flag (sell_single) is superseded by the
 * Quantity & Service Behaviour control: 'none' (sold without a quantity
 * selector) now drives the single-unit behaviour. This backfills existing
 * rows so the two sources agree, and flips the column default to
 * 'multiple_services' so new products keep the legacy multi-unit default.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sell_single=true means single-unit → 'none'.
        DB::table('products')->where('sell_single', true)->update(['quantity_behaviour' => 'none']);

        // Legacy rows that never touched the new field defaulted to 'none'
        // while behaving multi-unit (sell_single=false) — align them.
        DB::table('products')
            ->where('sell_single', false)
            ->where('quantity_behaviour', 'none')
            ->update(['quantity_behaviour' => 'multiple_services']);

        Schema::table('products', function (Blueprint $table) {
            $table->enum('quantity_behaviour', ['none', 'multiple_services', 'scaling'])
                ->default('multiple_services')
                ->change();
        });
    }

    public function down(): void
    {
        // Data backfill is not reversed; the column default is a schema
        // change with no meaningful rollback for existing rows.
    }
};
