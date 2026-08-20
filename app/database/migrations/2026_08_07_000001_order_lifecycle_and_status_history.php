<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriches the order lifecycle with a paid/provisioning/failed track and an
 * order status history audit trail (gap-fillup T1.2).
 *
 * The original ported enum (2026_07_30_120030) supported only pending/active/
 * suspended/cancelled/terminated. The richer state machine (gap-fillup plan:
 * pending → paid → provisioning → active) needs `paid`, `provisioning` and
 * `failed` as first-class statuses — additive, non-destructive. The history
 * table records every guarded status hop (same audit idiom as
 * ip_allocation_history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'active', 'suspended', 'cancelled', 'terminated',
                'paid', 'provisioning', 'failed',
            ])->default('pending')->change();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');

        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'suspended', 'cancelled', 'terminated'])
                ->default('pending')->change();
        });
    }
};
