<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `suspended` to the domains.status enum.
 *
 * The reference state machine (DomainStatus) supports active/pending/expired/
 * suspended/transferred and the CRM exposes a bulk "suspend" action, but the
 * original ported enum in 2026_07_30_120030 omitted `suspended`. This additive
 * migration completes the lifecycle (same treatment as invoices 'partial').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'suspended', 'expired', 'cancelled', 'transferred', 'pending_transfer', 'redemption'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled', 'transferred', 'pending_transfer', 'redemption'])
                ->default('pending')
                ->change();
        });
    }
};
