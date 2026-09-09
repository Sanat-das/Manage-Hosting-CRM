<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order enters the system: on activation (pending ->
 * active) and when a customer places one from the storefront.
 *
 * The storefront used to dispatch nothing at all, so a customer order produced
 * no notification for the customer and — more damagingly — none for the
 * admins, who had to notice the row in the orders list. Listeners must
 * therefore read $order->status rather than assume the order is active.
 *
 * Keep it minimal — constructor + the order.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
