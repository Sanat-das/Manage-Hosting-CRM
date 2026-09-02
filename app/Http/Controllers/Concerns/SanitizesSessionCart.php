<?php

namespace App\Http\Controllers\Concerns;

/**
 * Session-cart sanitization shared by the client storefront and admin cart.
 *
 * 'free' is a payment-type marker (the pricing matrix stores a free row for
 * free products), never a valid orderable billing cycle — Order::BILLING_CYCLES
 * excludes it. Entries carrying it are leftovers from before the storefront
 * stopped offering it as a cycle; they are dropped on first touch so they can
 * never be displayed, updated or ordered again.
 */
trait SanitizesSessionCart
{
    /**
     * Read the session cart, dropping stale 'free' billing-cycle entries.
     *
     * The cleaned cart is written back to the session only when something was
     * actually removed, keeping the operation idempotent and cheap on the
     * common path.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeCart(): array
    {
        $cart = session()->get('cart', []);

        $clean = array_values(array_filter(
            $cart,
            fn (array $entry): bool => ($entry['billing_cycle'] ?? 'monthly') !== 'free'
        ));

        if (count($clean) !== count($cart)) {
            session()->put('cart', $clean);
        }

        return $clean;
    }
}