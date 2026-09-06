<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add `virtualizor` to servers.panel_type.
 *
 * `products.provisioning_module` has offered virtualizor since the first
 * product migration, but `servers.panel_type` only ever allowed
 * cpanel/plesk/directadmin/custom. ServerAllocator matches one against the
 * other, so a Virtualizor product could never be allocated a server: the
 * closest an admin could do was mark the node `custom`, which the allocator
 * (correctly) refuses to hand to the virtualizor module.
 *
 * Uses the schema builder rather than a raw MySQL ALTER: SQLite enforces an
 * enum through a CHECK constraint, so it needs widening there too and a
 * driver-guarded ALTER would leave the test database rejecting the new value.
 */
return new class extends Migration
{
    private const PANEL_TYPES = ['cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'];

    private const PREVIOUS = ['cpanel', 'plesk', 'directadmin', 'custom'];

    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->enum('panel_type', self::PANEL_TYPES)->default('cpanel')->change();
        });
    }

    public function down(): void
    {
        // Anything left on the removed value would violate the narrowed enum.
        DB::table('servers')->where('panel_type', 'virtualizor')->update(['panel_type' => 'custom']);

        Schema::table('servers', function (Blueprint $table) {
            $table->enum('panel_type', self::PREVIOUS)->default('cpanel')->change();
        });
    }
};
