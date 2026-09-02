<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for module events and failures. No updated_at.
 * service_instance_id deliberately has NO foreign key — service instances
 * may be deleted while their log rows must survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('event');
            $table->unsignedBigInteger('service_instance_id')->nullable();
            $table->string('status')->default('info');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('module_id')->references('id')->on('modules')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_log');
    }
};
