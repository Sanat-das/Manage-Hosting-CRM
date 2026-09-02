<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOptionGroup;

/**
 * Order configuration snapshot (product options).
 *
 * THE single writer of the `order_items.config_options` JSON payload — no
 * other code may build this shape. capture() walks the product's option links
 * (`product_option_group_product` pivot rows, ordered by pivot id) and emits
 * one entry per link with its group, type, value labels and — for
 * customer-editable links — the customer's selection at order time.
 *
 * formatPrice() applies the per-billing-cycle price modifiers of the selected
 * option values on top of the product's base price.
 */
class OrderConfigSnapshot
{
    /**
     * Capture the product's option configuration into a snapshot array.
     *
     * Informational links carry their full `values` list with a null
     * selection; customer-editable links additionally receive the submitted
     * `selected` value(s) keyed by link id in `$selections`.
     *
     * @param  Product  $product  product whose option links are snapshotted
     * @param  OrderItem|null  $item  persisted order item (accepted for API
     *                                compatibility / future use; not used yet)
     * @param  array<string, mixed>  $selections  customer selections keyed by link id
     * @return array{
     *     product_group_name: string|null,
     *     provisioning_module: string|null,
     *     options: array<int, array<string, mixed>>,
     * }
     */
    public function capture(Product $product, ?OrderItem $item = null, array $selections = []): array
    {
        $links = $product->optionLinks()
            ->with(['group', 'linkValues.pricing', 'unitPricing'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $options = [];

        foreach ($links as $link) {
            $options[] = [
                'id' => $link->id,
                'group' => $link->group?->name,
                'type' => $link->group?->type,
                'customer_editable' => (bool) $link->customer_editable,
                'values' => $link->linkValues->pluck('label')->all(),
                'selected' => $selections[$link->id] ?? null,
                // Per-billing-cycle unit prices for continuous groups
                // (slider / number / quantity); empty for discrete groups.
                'unit_pricing' => $link->unitPricing
                    ->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])
                    ->all(),
            ];
        }

        return [
            'product_group_name' => $product->group?->name,
            'provisioning_module' => $product->provisioning_module,
            'options' => $options,
        ];
    }

    /**
     * Apply the per-cycle price modifiers for the requested billing cycle.
     *
     * Uses the modifier keyed by `$cycle` when present; otherwise falls back to
     * the 'monthly' modifier, then a single-entry map verbatim. The fallbacks
     * are gated by `$enabledCycles` (the product's offered billing cycles): a
     * fallback cycle is only used when it is enabled on the product. An empty
     * `$enabledCycles` keeps the legacy un-gated behaviour.
     *
     * @param  array<string, float|int>  $modifiers  per-billing-cycle modifier map
     * @param  list<string>  $enabledCycles  billing cycles offered by the product
     */
    public static function formatPrice(float $base, array $modifiers, string $cycle, array $enabledCycles = []): float
    {
        if (array_key_exists($cycle, $modifiers)) {
            return (float) $base + (float) $modifiers[$cycle];
        }

        $gated = fn (string $candidate): bool => $enabledCycles === [] || in_array($candidate, $enabledCycles, true);

        if ($gated('monthly') && array_key_exists('monthly', $modifiers)) {
            return (float) $base + (float) $modifiers['monthly'];
        }

        if (count($modifiers) === 1) {
            $only = array_key_first($modifiers);

            if ($gated($only)) {
                return (float) $base + (float) $modifiers[$only];
            }
        }

        return (float) $base;
    }

    /**
     * The per-billing-cycle price adjustment of the given option selections.
     *
     * Sums the selected values' modifiers (per the product's customer-editable
     * option links) into a billing_cycle => modifier map — the same shape
     * formatPrice() consumes. Continuous links (slider / number / quantity)
     * multiply the entered value by the link's per-cycle unit price; discrete
     * links add the selected values' modifiers. Selections for non-editable
     * (informational) links are ignored.
     *
     * @param  Product  $product  product whose option links are priced
     * @param  array<string, mixed>  $selections  selections keyed by link id
     * @return array<string, float> billing_cycle => summed modifier
     */
    public static function adjustmentsFor(Product $product, array $selections): array
    {
        $modifiers = [];

        $links = $product->optionLinks()
            ->with(['group', 'linkValues.pricing', 'unitPricing'])
            ->get();

        foreach ($links as $link) {
            if (! $link->customer_editable) {
                continue;
            }

            $selected = $selections[$link->id] ?? null;
            if ($selected === null) {
                continue;
            }

            // Continuous types (slider / number / quantity) are priced per
            // unit: the submitted numeric value multiplies the link's unit
            // price. Empty unit pricing (legacy groups) adds nothing.
            if (ProductOptionGroup::isContinuousType($link->group?->type)) {
                $value = (float) $selected;

                foreach ($link->unitPricing as $price) {
                    $cycle = $price->billing_cycle;
                    $modifiers[$cycle] = ($modifiers[$cycle] ?? 0.0) + $value * (float) $price->price_modifier;
                }

                continue;
            }

            $labels = is_array($selected) ? $selected : [$selected];

            foreach ($labels as $label) {
                $value = $link->linkValues->firstWhere('label', $label);
                if ($value === null) {
                    continue;
                }

                foreach ($value->pricing as $price) {
                    $cycle = $price->billing_cycle;
                    $modifiers[$cycle] = ($modifiers[$cycle] ?? 0.0) + (float) $price->price_modifier;
                }
            }
        }

        return $modifiers;
    }
}
