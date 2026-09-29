<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot the place of supply on the tax invoice itself. Deriving it at
 * render time from the customer's CURRENT state_code would change a
 * historical document when the customer moves state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('place_of_supply_code', 2)->nullable()->after('igst_amount');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'place_of_supply_code')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('place_of_supply_code');
            });
        }
    }
};
