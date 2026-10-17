<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `refund` to the customer_wallet.type enum.
 *
 * The admin invoice-refund flow (BillingService::refund) credits the customer's
 * credit balance and writes a wallet ledger row with type = 'refund', but the
 * enum finalised in 2026_07_30_120040 stops at deposit/credit/debit/
 * invoice_payment, so the insert is truncated under STRICT_TRANS_TABLES. This
 * additive migration completes the set (same treatment as domains 'suspended').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_wallet', function (Blueprint $table) {
            $table->enum('type', ['deposit', 'credit', 'debit', 'invoice_payment', 'refund'])
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_wallet', function (Blueprint $table) {
            $table->enum('type', ['deposit', 'credit', 'debit', 'invoice_payment'])
                ->change();
        });
    }
};
