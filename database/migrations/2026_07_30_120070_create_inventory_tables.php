<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('racks', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('datacenter_id');
            $table->string('name');
            $table->unsignedInteger('u_height')->default(42);
            $table->unsignedInteger('u_available')->default(42);
            $table->unsignedInteger('power_capacity_watts')->nullable();
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->timestamps();
        });

        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_tag')->unique();
            $table->string('serial_number')->nullable();
            $table->enum('asset_type', ['server','ram_module','cpu','ssd','hdd','gpu','raid_controller','nic','switch','pdu','other_hardware','software_license','ipv4_address','ipv6_address','ssl_certificate','domain']);
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('vendor')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->unsignedInteger('datacenter_id')->nullable();
            $table->unsignedInteger('rack_id')->nullable();
            $table->unsignedInteger('rack_u_position')->nullable();
            $table->unsignedInteger('parent_asset_id')->nullable();
            $table->enum('status', ['ordered','received','in_stock','installed','assigned','maintenance','retired','disposed'])->default('in_stock');
            $table->enum('lifecycle_state', ['ordered','received','in_stock','installed','assigned','maintenance','retired','disposed'])->default('ordered');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['asset_type', 'status']);
            $table->index('datacenter_id');
            $table->index('rack_id');
            $table->index('parent_asset_id');
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->unique()->constrained('inventory_assets')->restrictOnDelete();
            $table->enum('license_type', ['windows','cpanel','plesk','litespeed','cloudlinux','directadmin','virtualizor','solusvm','other']);
            $table->string('license_key')->nullable();
            $table->unsignedInteger('seats')->default(1);
            $table->unsignedInteger('seats_available')->default(1);
            $table->string('vendor')->nullable();
            $table->string('purchase_order')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->enum('status', ['active', 'expired', 'revoked', 'pending'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('license_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained('licenses')->restrictOnDelete();
            $table->enum('assigned_to_type', ['service', 'customer', 'server']);
            $table->unsignedInteger('assigned_to_id');
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();
            $table->text('notes')->nullable();

            $table->index('license_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_assignments');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('inventory_assets');
        Schema::dropIfExists('racks');
    }
};
