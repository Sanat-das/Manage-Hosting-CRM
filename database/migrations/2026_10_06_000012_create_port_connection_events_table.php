<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('port_connection_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('port_connection_id')->nullable();
            $table->unsignedBigInteger('port_a_id');
            $table->unsignedBigInteger('port_b_id');
            $table->string('a_asset_tag', 255);
            $table->string('a_port_name', 100);
            $table->string('b_asset_tag', 255);
            $table->string('b_port_name', 100);
            $table->string('action', 16);
            $table->string('cable_label', 100)->nullable();
            $table->string('cable_type', 32)->nullable();
            $table->decimal('cable_length_m', 6, 2)->nullable();
            $table->string('cable_color', 16)->nullable();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->timestamp('changed_at');
            $table->text('notes')->nullable();

            $table->index('changed_at');
            $table->index('port_connection_id');
            $table->index(['port_a_id', 'port_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('port_connection_events');
    }
};
