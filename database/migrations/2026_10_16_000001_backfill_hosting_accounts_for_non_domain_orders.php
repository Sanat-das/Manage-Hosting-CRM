<?php

use App\Models\Order;
use App\Services\HostingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill the product/service (hosting_accounts) rows that older code never
 * created for non-domain products, so those orders are visible under
 * Products/Services instead of only in the order list. Idempotent: orders that
 * already have an account are skipped (provisionFromOrder is a no-op there).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('hosting_accounts')) {
            return;
        }

        $hosting = app(HostingService::class);

        Order::query()
            ->with('product')
            ->whereDoesntHave('hostingAccount')
            ->chunkById(100, function ($orders) use ($hosting): void {
                foreach ($orders as $order) {
                    try {
                        $hosting->provisionFromOrder($order);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Could not backfill hosting account', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Non-destructive: no rows are removed.
    }
};