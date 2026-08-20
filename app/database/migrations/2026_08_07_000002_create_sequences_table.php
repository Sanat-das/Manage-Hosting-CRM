<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sequence counter for race-safe order/invoice numbering (gap-fillup T1.3).
 *
 * A single keyed counter row, read-and-incremented under a row lock inside a
 * transaction, so concurrent order placements cannot produce duplicate numbers
 * (the previous `count()+1` + exists-recheck approach in OrderController was
 * vulnerable to a race across two simultaneous placements).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        DB::table('sequences')->insert(['key' => 'order_no', 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
