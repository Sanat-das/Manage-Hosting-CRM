<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the products.provisioning_module value 'none' to 'manual'.
 *
 * The product provisioning module now drives the post-payment order flow:
 * 'manual' means the service awaits manual provisioning after payment, while
 * the automated modules (cpanel/plesk/directadmin/virtualizor/custom)
 * auto-provision. There is no longer a "no provisioning" module value.
 *
 * The enum is widened first (manual admitted alongside none), then the data is
 * migrated, then 'none' is dropped — a sequence that satisfies both MySQL
 * (updates must stay inside the live enum) and SQLite (table rebuild with a
 * CHECK constraint) without driver-specific SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['none', 'manual', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('manual')->change();
        });

        DB::table('products')
            ->where('provisioning_module', 'none')
            ->update(['provisioning_module' => 'manual']);

        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['manual', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['none', 'manual', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('none')->change();
        });

        DB::table('products')
            ->where('provisioning_module', 'manual')
            ->update(['provisioning_module' => 'none']);

        Schema::table('products', function (Blueprint $table) {
            $table->enum('provisioning_module', ['none', 'cpanel', 'plesk', 'directadmin', 'virtualizor', 'custom'])->default('none')->change();
        });
    }
};
