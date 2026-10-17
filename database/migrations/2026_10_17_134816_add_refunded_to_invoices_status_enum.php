<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `refunded` to the invoices.status enum.
 *
 * Invoice::STATUS_REFUNDED, its STATUS_TRANSITIONS (paid|partial → refunded)
 * and BillingService::refundInvoice all use the value, and
 * Admin\InvoiceController exposes the transition, but the enum finalised in
 * 2026_07_31_000001 stops at seven values. Any refund therefore fails with
 * "Data truncated for column 'status'". Same treatment as domains 'suspended'
 * (2026_08_01_000001) and invoices 'partial'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'partial', 'void', 'cancelled', 'refunded'])
                ->default('draft')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'partial', 'void', 'cancelled'])
                ->default('draft')
                ->change();
        });
    }
};
