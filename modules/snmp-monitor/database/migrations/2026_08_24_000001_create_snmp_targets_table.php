<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One SNMP polling target per hosting account (unique hosting_account_id).
 * A null `host` means the address is auto-resolved from the account's IPAM
 * leases at ensure/poll time (public subnet first, then legacy type=public,
 * then any lease). This table intentionally holds NO credentials — SNMP
 * community/auth secrets stay in the product module config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snmp_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained('hosting_accounts')->cascadeOnDelete();
            $table->string('host', 45)->nullable();
            $table->smallInteger('port')->default(161);
            $table->enum('target_os', ['linux', 'windows']);
            $table->unsignedSmallInteger('poll_interval')->nullable();
            $table->boolean('enabled')->default(true);
            $table->enum('status', ['up', 'down', 'unknown'])->default('unknown');
            $table->smallInteger('consecutive_failures')->default(0);
            $table->timestamp('last_polled_at', 3)->nullable()->index();
            $table->timestamp('next_poll_at', 3)->nullable()->index();
            $table->smallInteger('last_response_ms')->nullable();
            $table->timestamps();
            $table->unique(['hosting_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snmp_targets');
    }
};
