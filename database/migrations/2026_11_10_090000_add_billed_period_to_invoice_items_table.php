<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the billing period an invoice line billed.
 *
 * The renewal engine issues an invoice and moves the service's next billing
 * date forward in the same transaction, so an invoice that dies (voided,
 * cancelled, written off) has to give the period back — otherwise the
 * service silently stops billing for it until the next cycle. Rewinding
 * needs to know which period was billed and how long it was, because the
 * schedule is advanced by the item's cycle from its OWN due date.
 *
 * `billed_due_date` is that due date; `billed_cycle_months` is the cycle the
 * advance applied. Both are null on lines that never advanced a schedule
 * (hand-written invoices, order invoices).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->date('billed_due_date')->nullable()->after('total');
            $table->unsignedSmallInteger('billed_cycle_months')->nullable()->after('billed_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['billed_due_date', 'billed_cycle_months']);
        });
    }
};
