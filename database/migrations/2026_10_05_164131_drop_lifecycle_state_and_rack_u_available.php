<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire two columns that never carried trustworthy data:
 *
 *  - inventory_assets.lifecycle_state duplicated the `status` enum verbatim
 *    and was unreachable from every controller/form path (seeder-only).
 *  - racks.u_available was manual bookkeeping that silently drifted from the
 *    assets actually placed in the rack. Rack occupancy is now derived from
 *    the assets table at display time.
 *
 * Rollback restores the original definitions and defaults; the dropped
 * values themselves cannot be recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropColumn('lifecycle_state');
        });

        Schema::table('racks', function (Blueprint $table) {
            $table->dropColumn('u_available');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->enum('lifecycle_state', ['ordered', 'received', 'in_stock', 'installed', 'assigned', 'maintenance', 'retired', 'disposed'])->default('ordered');
        });

        Schema::table('racks', function (Blueprint $table) {
            $table->unsignedInteger('u_available')->default(42);
        });
    }
};
