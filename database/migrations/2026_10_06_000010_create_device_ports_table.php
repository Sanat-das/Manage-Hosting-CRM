<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_ports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->constrained('inventory_assets')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('port_number', 50)->nullable();
            $table->string('port_type', 32)->default('other');
            $table->string('media', 16)->nullable();
            $table->unsignedBigInteger('speed_bps')->nullable();
            $table->string('status', 16)->default('unknown');
            $table->string('mac_address', 17)->nullable();
            $table->string('source', 8)->default('manual');
            $table->unsignedInteger('snmp_if_index')->nullable();
            $table->string('snmp_if_descr', 255)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['inventory_asset_id', 'name'], 'device_ports_asset_name_unique');
            $table->unique(['inventory_asset_id', 'snmp_if_index'], 'device_ports_asset_ifindex_unique');
            $table->index('mac_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_ports');
    }
};
