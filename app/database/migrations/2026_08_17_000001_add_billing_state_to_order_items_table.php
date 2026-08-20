<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-service billing state (WHMCS model): an order is a one-time
        // transaction; each order item (the purchased product/service) carries
        // its own renewal state — the domain it was provisioned against, its
        // own billing cycle cadence and cycle counter. Renewals are driven
        // from these columns, never from the order row.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('domain_name', 253)->nullable()->after('billing_cycle');
            $table->date('next_billing_date')->nullable()->after('domain_name');
            $table->date('last_billing_date')->nullable()->after('next_billing_date');
            // Snapshot of the product's recurring_cycles_limit at order time
            // (0 = unlimited); stable even if the product is edited later.
            $table->unsignedInteger('recurring_cycles_limit')->default(0)->after('last_billing_date');
            // Billing cycles invoiced for this item (the initial invoice
            // counts as cycle 1). NULL marks legacy items created before this
            // migration — they fall back to counting the order's invoices.
            $table->unsignedInteger('billing_cycles_count')->nullable()->after('recurring_cycles_limit');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'domain_name',
                'next_billing_date',
                'last_billing_date',
                'recurring_cycles_limit',
                'billing_cycles_count',
            ]);
        });
    }
};
