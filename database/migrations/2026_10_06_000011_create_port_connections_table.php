<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('port_a_id')->constrained('device_ports')->cascadeOnDelete();
            $table->foreignId('port_b_id')->constrained('device_ports')->cascadeOnDelete();
            $table->string('cable_label', 100)->nullable();
            $table->string('cable_type', 32)->nullable();
            $table->decimal('cable_length_m', 6, 2)->nullable();
            $table->string('cable_color', 16)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('port_a_id', 'port_connections_port_a_unique');
            $table->unique('port_b_id', 'port_connections_port_b_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_connections');
    }
};
