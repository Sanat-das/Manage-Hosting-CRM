<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewal_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->date('cycle_date');
            $table->unsignedSmallInteger('days_before');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'cycle_date', 'days_before'], 'renewal_reminder_logs_cycle_unique');
            $table->index('cycle_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_reminder_logs');
    }
};
