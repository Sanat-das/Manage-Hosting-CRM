<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order is activated (pending -> active).
 *
 * This is the automated provisioning trigger stub: the real provisioning
 * engine (Session 3B) and webhook delivery (Session 5) listen on this event.
 * Keep it minimal — constructor + the activated order.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
