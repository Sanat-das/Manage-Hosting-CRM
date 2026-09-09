<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;

/**
 * Order configuration snapshot (product options).
 *
 * THE single writer of the `order_items.config_options` JSON payload — no
 * other code may build this shape. capture() walks the product's option links
 * (`product_option_group_product` pivot rows, in display order) and emits one
 * entry per link describing the feature the customer received: which group,
 * which value, and what that value added to the price.
 *
 * The money itself belongs to OptionPricingResolver — this class only records
 * what the resolver decided, so an order, an invoice and a service page can
 * each be rendered from the snapshot alone, without re-reading a catalog that
 * may since have changed.
 */
class OrderConfigSnapshot
{
    public function __construct(
        private readonly OptionPricingResolver $pricing = new OptionPricingResolver,
    ) {}

    /**
     * Capture the product's option configuration into a snapshot array.
     *
     * Every option link is recorded, fixed and configurable alike: a FIXED
     * link resolves to the value the product declares (the one flagged
     * `is_default`), a configurable one to what the customer picked. Both
     * carry the price that was applied, resolved for `$cycle` — the order's
     * billing cycle, which the options follow.
     *
     * The shape is additive: every key earlier versions wrote is still
     * written, so snapshots taken before this change keep rendering through
     * the same views.
     *
     *   root:  product_group_name, provisioning_module, billing_cycle, options
     *   entry: id, key, group, unit, type, customer_editable, required,
     *          values, value_id, value_ids, selected, unit_pricing,
     *          price_unit, price_applied
     *
     * `price_unit` is the rate used for this cycle (a value's modifier, or the
     * per-unit rate of a continuous option) and `price_applied` is what the
     * option added to one unit of the product. They are named apart from
     * `unit_pricing`, which stays the raw per-cycle map it always was.
     *
     * @param  Product  $product  product whose option links are snapshotted
     * @param  OrderItem|null  $item  persisted order item (accepted for API
     *                                compatibility / future use; not used yet)
     * @param  array<string, mixed>  $selections  customer selections keyed by link id
     * @param  string|null  $cycle  the order's billing cycle; defaults to the product's own
     * @return array{
     *     product_group_name: string|null,
     *     provisioning_module: string|null,
     *     billing_cycle: string,
     *     options: array<int, array<string, mixed>>,
     * }
     */
    public function capture(Product $product, ?OrderItem $item = null, array $selections = [], ?string $cycle = null): array
    {
        $links = OptionPricingResolver::loadLinks($product);
        $priced = $this->pricing->resolve($product, $selections, $cycle, $links);

        $options = [];

        foreach ($links as $link) {
            $line = $priced['lines'][$link->id] ?? [
                'value_id' => null,
                'value_ids' => [],
                'selected' => null,
                'price_unit' => null,
                'price_applied' => 0.0,
            ];

            $options[] = [
                'id' => $link->id,
                // Machine-readable feature handle (ram / cpu / disk) for
                // provisioning; the group's display name stays in `group`.
                'key' => $link->group?->key,
                'group' => $link->group?->name,
                'unit' => $link->group?->unit,
                'type' => $link->group?->type,
                'customer_editable' => (bool) $link->customer_editable,
                'required' => (bool) ($link->required ?? true),
                'values' => $link->linkValues->pluck('label')->all(),
                'value_id' => $line['value_id'],
                'value_ids' => $line['value_ids'],
                'selected' => $line['selected'],
                // Per-billing-cycle unit prices for continuous groups
                // (slider / number / quantity); empty for discrete groups.
                'unit_pricing' => $link->unitPricing
                    ->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])
                    ->all(),
                'price_unit' => $line['price_unit'],
                'price_applied' => $line['price_applied'],
            ];
        }

        return [
            'product_group_name' => $product->group?->name,
            'provisioning_module' => $product->provisioning_module,
            'billing_cycle' => $priced['cycle'],
            'options' => $options,
        ];
    }

    /**
     * Apply an option modifier map to a base price for one billing cycle.
     *
     * The rate for the cycle comes from OptionPricingResolver::rateForCycle():
     * the exact rate when one was entered for that cycle, otherwise the
     * monthly rate multiplied by the months in the cycle — an option is billed
     * on the same cycle as the product it belongs to. `one_time` spans no
     * months and so never derives.
     *
     * This previously applied a *bare* monthly modifier to any cycle, which
     * charged one month of an option against a year of service.
     *
     * @param  array<string, float|int>  $modifiers  per-billing-cycle modifier map
     * @param  list<string>  $enabledCycles  no longer used: derivation makes the
     *                                       old "is this cycle sold?" gate moot,
     *                                       because the monthly rate is the
     *                                       option's unit of price rather than a
     *                                       substitute cycle. Kept so existing
     *                                       callers need no edit.
     */
    public static function formatPrice(float $base, array $modifiers, string $cycle, array $enabledCycles = []): float
    {
        return (float) $base + OptionPricingResolver::rateForCycle($modifiers, $cycle);
    }

    /**
     * What the product's options add to one unit of it, for one billing cycle.
     *
     * Returned as the single-entry `[$cycle => adjustment]` map that
     * formatPrice() consumes, so the existing
     * `formatPrice($base, adjustmentsFor(...), $cycle)` call sites keep
     * working and pick up the exact-cycle branch (the amount is already
     * resolved for `$cycle` here — it is never derived twice).
     *
     * FIXED links are included: a feature the product declares is charged like
     * one the customer chose. Delegates to OptionPricingResolver, which owns
     * the rules.
     *
     * @param  Product  $product  product whose option links are priced
     * @param  array<string, mixed>  $selections  selections keyed by link id
     * @param  string  $cycle  the order's billing cycle
     * @return array<string, float> single-entry billing_cycle => adjustment
     */
    public static function adjustmentsFor(Product $product, array $selections, string $cycle = 'monthly'): array
    {
        $resolver = new OptionPricingResolver;

        return [$cycle => $resolver->adjustment($product, $selections, $cycle)];
    }
}
