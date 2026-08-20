<?php

namespace App\Services;

use App\Models\Product;

/**
 * Bundle pricing (Tier 4.4).
 *
 * A bundle product has no price of its own: its price is derived from the
 * component rows in `product_bundles`, each priced through the component's
 * own `product_pricing` rows for the requested billing cycle.
 *
 * Discounts are configured per component row:
 *   - 'percent' — discount_value percent of that component's line subtotal;
 *   - 'fixed'   — discount_value subtracted flat from that component's line
 *     subtotal (never below 0).
 *
 * The service returns a single aggregate quote (subtotal / discount / total)
 * where total = subtotal - discount and every line item total is already
 * discounted, so cart expansion can split the bundle into component lines
 * that sum exactly to the bundle price.
 *
 * Returns null when the product is not a bundle, when a component has no
 * ProductPricing row for the cycle, or when the bundle has no components.
 */
class ProductBundlePricingService
{
    /**
     * @return array{
     *     cycle: string,
     *     bundle_id: int,
     *     bundle_name: string,
     *     line_items: array<int, array<string, mixed>>,
     *     subtotal: float,
     *     discount: float,
     *     total: float,
     * }|null
     */
    public function priceFor(Product $bundle, string $cycle): ?array
    {
        if (! $bundle->isBundle()) {
            return null;
        }

        $rows = $bundle->bundleChildren()
            ->with('component')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $lineItems = [];
        $subtotal = 0.0;
        $discount = 0.0;

        foreach ($rows as $row) {
            $component = $row->component;

            $pricing = $component->pricing()->where('billing_cycle', $cycle)->first();
            if ($pricing === null) {
                return null;
            }

            $quantity = max(1, (int) $row->quantity);
            $unitPrice = (float) $pricing->price;
            $lineSubtotal = round($unitPrice * $quantity, 2);

            $lineDiscount = $row->discount_type === 'fixed'
                ? min((float) $row->discount_value, $lineSubtotal)
                : round($lineSubtotal * ((float) $row->discount_value / 100), 2);

            $subtotal += $lineSubtotal;
            $discount += $lineDiscount;

            $lineItems[] = [
                'product_id' => $component->id,
                'name' => $component->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_type' => $row->discount_type,
                'discount_value' => (float) $row->discount_value,
                'line_subtotal' => $lineSubtotal,
                'discount' => $lineDiscount,
                'total' => round($lineSubtotal - $lineDiscount, 2),
            ];
        }

        $subtotal = round($subtotal, 2);
        $discount = round($discount, 2);

        return [
            'cycle' => $cycle,
            'bundle_id' => $bundle->id,
            'bundle_name' => $bundle->name,
            'line_items' => $lineItems,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => round($subtotal - $discount, 2),
        ];
    }
}
