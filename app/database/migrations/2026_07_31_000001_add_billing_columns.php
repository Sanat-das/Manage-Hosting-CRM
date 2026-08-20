<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing engine support columns/tables (Session 3A.1).
 *
 * Adds the pieces the ported billing logic needs but the Session 2 schema
 * lacks — all four were identified in the reference audit (learnings.md):
 *
 *  1. invoices.status gains 'partial'  (decisions.md #1 — 7-value superset)
 *  2. invoices.paid_amount / last_reminder_at / reminder_count
 *     (decisions.md #5 — read at runtime by handlePartialPayment + sendReminders)
 *  3. quote_items table                (decisions.md #4 — total_price column)
 *  4. billing_cycles table             (BillModel reads it; absent from both
 *     reference schema.sql and local migrations — created ONLY if missing)
 *  5. customers.state_code             (reference resolves intra/inter-state via
 *     `SELECT state FROM customers` (ApiRoutes L960) but the base schema never
 *     defines it; the renewal-IGST fix (decisions.md #6) needs a home for it)
 */
return new class extends Migration
{
    private bool $createdBillingCycles = false;

    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // 7-value status superset per decisions.md #1.
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'partial', 'void', 'cancelled'])
                ->default('draft')
                ->change();

            // Legacy columns read at runtime by the ported billing logic.
            $table->decimal('paid_amount', 14, 2)->default(0)->after('total');
            $table->timestamp('last_reminder_at')->nullable()->after('paid_at');
            $table->unsignedInteger('reminder_count')->default(0)->after('last_reminder_at');
        });

        // Quote line items — port of QuoteModel::createWithItems; the reference
        // schema column is total_price (decisions.md #4), here named qty/total_price
        // per the billing task spec, with GST fields for tax-aware quotes.
        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->enum('gst_type', ['standard', 'exempt', 'reverse_charge'])->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->index('quote_id');
        });

        // Billing cycles — BillModel.fillable + its queries define the shape:
        // customer_id, cycle_start, cycle_end, total_amount, paid_amount,
        // status ENUM(pending,partial,paid,cancelled). Create only if missing.
        if (! Schema::hasTable('billing_cycles')) {
            $this->createdBillingCycles = true;

            Schema::create('billing_cycles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->date('cycle_start');
                $table->date('cycle_end');
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->decimal('paid_amount', 12, 2)->default(0);
                $table->enum('status', ['pending', 'partial', 'paid', 'cancelled'])->default('pending');
                $table->timestamps();
                $table->index('customer_id');
            });
        }

        // Canonical customer-state source for the renewal-IGST fix.
        if (! Schema::hasColumn('customers', 'state_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('state_code', 2)->nullable()->after('tax_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->enum('status', ['draft', 'sent', 'paid', 'overdue', 'void', 'cancelled'])
                ->default('draft')
                ->change();
            $table->dropColumn(['paid_amount', 'last_reminder_at', 'reminder_count']);
        });

        Schema::dropIfExists('quote_items');

        if ($this->createdBillingCycles) {
            Schema::dropIfExists('billing_cycles');
        }

        if (Schema::hasColumn('customers', 'state_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('state_code');
            });
        }
    }
};
