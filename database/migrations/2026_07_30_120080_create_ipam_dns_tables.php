<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_subnets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subnet_cidr', 43)->unique();
            $table->string('gateway')->nullable();
            $table->string('netmask', 15)->nullable();
            $table->enum('ip_version', ['4', '6'])->default('4');
            $table->enum('network_type', ['public', 'private', 'management', 'storage', 'dmz'])->default('private');
            $table->unsignedInteger('vlan_id')->nullable();
            $table->unsignedInteger('datacenter_id')->nullable();
            $table->unsignedInteger('total_addresses')->default(0);
            $table->unsignedInteger('used_addresses')->default(0);
            $table->unsignedInteger('reserved_count')->default(0);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'exhausted', 'reserved', 'retired'])->default('active');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->index('network_type');
            $table->index('status');
            $table->index('datacenter_id');
            $table->index('vlan_id');
        });

        Schema::create('vlans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('vlan_id')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('datacenter_id')->nullable();
            $table->unsignedInteger('subnet_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('datacenter_id');
        });

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subnet_id')->constrained('ip_subnets')->restrictOnDelete();
            $table->string('ip_address', 45);
            $table->enum('ip_version', ['4', '6'])->default('4');
            $table->enum('type', ['gateway','broadcast','network','reserved','available','assigned','floating','nat'])->default('available');
            $table->enum('assigned_to_type', ['service','server','customer','inventory'])->nullable();
            $table->unsignedInteger('assigned_to_id')->nullable();
            $table->unsignedInteger('inventory_asset_id')->nullable();
            $table->string('ptr_record')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['subnet_id', 'ip_address']);
            $table->index('type');
            $table->index(['assigned_to_type', 'assigned_to_id']);
            $table->index('inventory_asset_id');
        });

        Schema::create('ip_allocation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_address_id')->constrained('ip_addresses')->restrictOnDelete();
            $table->enum('action', ['allocated','released','reserved','unreserved','ptr_updated']);
            $table->string('previous_assigned_to_type')->nullable();
            $table->unsignedInteger('previous_assigned_to_id')->nullable();
            $table->string('new_assigned_to_type')->nullable();
            $table->unsignedInteger('new_assigned_to_id')->nullable();
            $table->unsignedInteger('changed_by_user_id')->nullable();
            $table->string('ip_address_snapshot', 45);
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();

            $table->index('ip_address_id');
            $table->index('changed_at');
        });

        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->enum('zone_type', ['forward', 'reverse'])->default('forward');
            $table->bigInteger('serial')->default(0);
            $table->integer('refresh')->default(3600);
            $table->integer('retry')->default(900);
            $table->integer('expire')->default(604800);
            $table->integer('ttl')->default(86400);
            $table->string('master_nameserver')->nullable();
            $table->string('admin_email')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });

        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('dns_zones')->restrictOnDelete();
            $table->string('name');
            $table->enum('type', ['A','AAAA','CNAME','MX','NS','TXT','SRV','PTR','SOA']);
            $table->string('content', 500);
            $table->integer('ttl')->default(3600);
            $table->integer('priority')->default(0);
            $table->unsignedInteger('service_id')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');

            $table->unique(['zone_id', 'name', 'type', 'content']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
        Schema::dropIfExists('ip_allocation_history');
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('vlans');
        Schema::dropIfExists('ip_subnets');
    }
};
