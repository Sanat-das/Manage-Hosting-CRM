<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the legacy single-unit flag.
 *
 * quantity_behaviour is the source of truth for quantity semantics ('none' =
 * sold as a single unit); the sell_single column was backfilled into it by
 * 2026_08_20_000004 and is no longer read anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sell_single');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sell_single')->default(false)->after('require_domain');
        });
    }
};
