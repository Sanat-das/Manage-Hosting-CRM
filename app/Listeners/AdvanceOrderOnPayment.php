<?php

namespace App\Listeners;

use App\Events\InvoicePaid;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Advance the order lifecycle when an order's invoice is fully paid.
 *
 * Bridges the billing and order worlds: before this listener existed, paying
 * an invoice marked the invoice 'paid' but left the linked order stuck in
 * 'pending' until an admin manually activated it. Now a settled invoice moves
 * the order pending → paid (via the guarded state machine, so it is idempotent
 * and audit-logged) and then follows the product's provisioning module:
 * 'manual' lands the order in 'provisioning' for admin handling, while
 * automated modules auto-provision straight to 'active' (or 'failed').
 *
 * Renewal invoices for already-active orders are a no-op here: only the
 * pending → paid hop is taken.
 */
class AdvanceOrderOnPayment
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice->fresh();

        if ($invoice->order_id === null || ! $invoice->isFullyPaid()) {
            return;
        }

        $order = Order::find($invoice->order_id);

        if ($order === null || $order->status !== Order::STATUS_PENDING) {
            return;
        }

        try {
            $this->orders->markPaid($order, "Marked paid on invoice {$invoice->invoice_no}");
            $this->orders->advanceAfterPayment($order);
        } catch (InvalidArgumentException $e) {
            Log::warning('Could not advance order on invoice payment', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
