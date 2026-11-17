<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two billing pointers per service (WHMCS model).
 *
 * `next_invoice_date` is the date the next renewal invoice is generated for;
 * it advances when the engine issues the invoice, so a period is never billed
 * twice. `next_billing_date` is the date the customer must pay by; it advances
 * only when the invoice covering it is paid. Before this migration the engine
 * moved `next_billing_date` at generation, which let an unpaid period count as
 * settled.
 *
 * `invoice_items.order_item_id` records the order line a billed period belongs
 * to, replacing the ambiguous product_id + date match in the schedule rewind
 * (several lines on one order can share a product).
 *
 * The backfills below are idempotent: they only touch rows that are still in
 * the pre-migration state, so a re-run after a partial deploy changes nothing.
 */
return new class extends Migration
{
    /** Invoice statuses whose period is still open (not settled, not dead). */
    private const OPEN_STATUSES = ['sent', 'overdue', 'partial'];

    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->date('next_invoice_date')->nullable()->after('next_billing_date')->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->date('next_invoice_date')->nullable()->after('next_billing_date');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('order_item_id')->nullable()->after('product_id')
                ->constrained('order_items')->nullOnDelete();
        });

        $this->backfillPointers();

        $this->backfillOpenInvoicePeriods();
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['next_invoice_date']);
            $table->dropColumn('next_invoice_date');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('next_invoice_date');
        });
    }

    /**
     * Seed the new pointer from the old one where nothing has been recorded
     * yet: before two pointers existed, the generation date and the pay-by date
     * were the same value.
     */
    private function backfillPointers(): void
    {
        DB::table('order_items')
            ->whereNull('next_invoice_date')
            ->whereNotNull('next_billing_date')
            ->update(['next_invoice_date' => DB::raw('next_billing_date')]);

        DB::table('orders')
            ->whereNull('next_invoice_date')
            ->whereNotNull('next_billing_date')
            ->update(['next_invoice_date' => DB::raw('next_billing_date')]);
    }

    /**
     * Give every open invoice's period back to its service.
     *
     * The old engine advanced `next_billing_date` when it generated the
     * invoice. Under the two-pointer model that advance belongs to the
     * generation pointer, so the pay-by pointer returns to the billed period
     * and the generation pointer sits one cycle ahead — exactly the state an
     * unpaid renewal leaves behind now.
     */
    private function backfillOpenInvoicePeriods(): void
    {
        $lines = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotNull('invoices.order_id')
            ->whereIn('invoices.status', self::OPEN_STATUSES)
            ->whereNotNull('invoice_items.billed_due_date')
            ->whereNotNull('invoice_items.billed_cycle_months')
            ->whereNotNull('invoice_items.product_id')
            ->select([
                'invoice_items.billed_due_date',
                'invoice_items.billed_cycle_months',
                'invoice_items.product_id',
                'invoices.order_id',
            ])
            ->orderBy('invoice_items.id')
            ->get();

        foreach ($lines as $line) {
            $billedDue = CarbonImmutable::parse($line->billed_due_date)->toDateString();
            $advancedTo = CarbonImmutable::parse($billedDue)
                ->addMonths((int) $line->billed_cycle_months)
                ->toDateString();

            $match = DB::table('order_items')
                ->where('order_id', $line->order_id)
                ->where('product_id', $line->product_id)
                ->whereDate('next_billing_date', $advancedTo);

            // Add-on rows carry the parent service's product_id, so they can
            // answer for the wrong line here. Prefer the service line; only
            // an order without one may fall back to an add-on row.
            $itemId = (clone $match)->whereNull('product_addon_id')->value('id')
                ?? $match->value('id');

            if ($itemId === null) {
                continue;
            }

            DB::table('order_items')->where('id', $itemId)->update([
                'next_billing_date' => $billedDue,
                'next_invoice_date' => $advancedTo,
            ]);
        }
    }
};
