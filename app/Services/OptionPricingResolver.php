<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use Illuminate\Support\Collection;

/**
 * THE single place configurable options turn into money.
 *
 * A configurable option is a product feature (RAM, CPU, Storage, Backup,
 * Support). It is either FIXED — declared by the product, the customer gets
 * the value flagged `is_default` — or CONFIGURABLE, where the customer picks.
 * Both cost what they cost: a fixed 8 GB of RAM is charged exactly like a
 * chosen one, and an admin who wants a feature bundled into the base price
 * prices it at 0.
 *
 * Three rules live here and nowhere else:
 *
 * 1. FIXED OPTIONS ARE PRICED. The previous pricing path opened with
 *    `if (! $link->customer_editable) continue;`, so every fixed option was
 *    silently free however large its modifier.
 *
 * 2. THE OPTION FOLLOWS THE PRODUCT'S CYCLE. A rate entered for the ordered
 *    cycle is used as-is; otherwise a monthly rate is multiplied up by the
 *    months in that cycle (149.00/mo on an annual order is 1788.00, not
 *    149.00). `one_time` spans no months, so it never derives — it uses its
 *    own rate or nothing.
 *
 * 3. SELECTIONS ARE RESOLVED BY LINK-VALUE ID. Labels are still accepted so a
 *    page a customer already had open keeps working, but a value is found by
 *    id first: matching on the label alone meant renaming a value orphaned its
 *    price and quietly charged zero.
 *
 * resolve() returns one line per option link — what the customer got, and what
 * it added to the unit price — which OrderConfigSnapshot persists so an order,
 * an invoice and a service can all be explained later.
 */
class OptionPricingResolver
{
    /**
     * Months spanned by a billing cycle, for deriving a cycle rate from the
     * monthly one. Wider than Order::CYCLE_MONTHS: option pricing rows use the
     * `product_pricing` vocabulary, which also has free/triennial. A zero span
     * means "not derivable" — free and one_time are one-off charges, not a run
     * of months.
     */
    public const CYCLE_MONTHS = [
        'free' => 0,
        'one_time' => 0,
        'monthly' => 1,
        'quarterly' => 3,
        'semi_annual' => 6,
        'annual' => 12,
        'biennial' => 24,
        'triennial' => 36,
    ];

    /**
     * Price a product's whole option configuration for one billing cycle.
     *
     * @param  array<int|string, mixed>  $selections  customer selections keyed by option link id
     * @param  string|null  $cycle  the ORDER's billing cycle; defaults to the product's own
     * @param  Collection<int, ProductOptionGroupProduct>|null  $links  preloaded links (with group,
     *                                                                  linkValues.pricing and unitPricing)
     * @return array{
     *     cycle: string,
     *     adjustment: float,
     *     lines: array<int, array{value_id: int|null, value_ids: list<int>, selected: mixed,
     *                             price_unit: float|null, price_applied: float}>
     * }
     */
    public function resolve(Product $product, array $selections = [], ?string $cycle = null, ?Collection $links = null): array
    {
        $cycle = $cycle ?: ((string) ($product->billing_cycle ?: 'monthly'));
        $links ??= self::loadLinks($product);

        $lines = [];
        $adjustment = 0.0;

        foreach ($links as $link) {
            $line = $this->resolveLink($link, $selections[$link->id] ?? null, $cycle);

            $lines[$link->id] = $line;
            $adjustment += $line['price_applied'];
        }

        return [
            'cycle' => $cycle,
            'adjustment' => round($adjustment, 2),
            'lines' => $lines,
        ];
    }

    /**
     * The total the options add to one unit of the product for a cycle.
     */
    public function adjustment(Product $product, array $selections = [], ?string $cycle = null): float
    {
        return $this->resolve($product, $selections, $cycle)['adjustment'];
    }

    /**
     * The product's option links with everything pricing and display need, in
     * the order they are shown.
     *
     * @return Collection<int, ProductOptionGroupProduct>
     */
    public static function loadLinks(Product $product): Collection
    {
        return $product->optionLinks()
            ->with(['group', 'linkValues.pricing', 'unitPricing'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The rate to charge for a billing cycle, given an option's rates keyed by
     * cycle: the exact rate when the admin entered one, else the monthly rate
     * multiplied by the months in the cycle, else nothing.
     *
     * @param  array<string, float|int|string>  $ratesByCycle
     */
    public static function rateForCycle(array $ratesByCycle, string $cycle): float
    {
        if (array_key_exists($cycle, $ratesByCycle)) {
            return (float) $ratesByCycle[$cycle];
        }

        $months = self::CYCLE_MONTHS[$cycle] ?? 0;

        if ($months > 0 && array_key_exists('monthly', $ratesByCycle)) {
            return round((float) $ratesByCycle['monthly'] * $months, 2);
        }

        return 0.0;
    }

    /**
     * The value a FIXED link is declared with: the one flagged is_default,
     * falling back to the first in display order for links that predate the
     * flag.
     */
    public static function defaultValue(ProductOptionGroupProduct $link): ?ProductOptionLinkValue
    {
        return $link->linkValues->firstWhere('is_default', true)
            ?? $link->linkValues->first();
    }

    /**
     * Price one option link against what the customer submitted for it.
     *
     * @return array{value_id: int|null, value_ids: list<int>, selected: mixed,
     *               price_unit: float|null, price_applied: float}
     */
    private function resolveLink(ProductOptionGroupProduct $link, mixed $submitted, string $cycle): array
    {
        $type = $link->group?->type;
        $editable = (bool) $link->customer_editable;

        if (ProductOptionGroup::isContinuousType($type)) {
            return $this->resolveContinuous($link, $editable ? $submitted : null, $cycle);
        }

        if ($type === 'text') {
            // Free-form text describes the service (a hostname); it carries no
            // price of its own.
            return $this->line(null, [], $editable && is_scalar($submitted) ? (string) $submitted : null, null, 0.0);
        }

        return $this->resolveDiscrete($link, $editable ? $submitted : null, $editable, $cycle);
    }

    /**
     * Slider / number / quantity: the customer's amount multiplies a per-unit
     * rate. A fixed continuous link has no amount to charge — a feature the
     * customer cannot change is modelled as a discrete value ("8 GB"), not as
     * a slider nobody can move.
     */
    private function resolveContinuous(ProductOptionGroupProduct $link, mixed $submitted, string $cycle): array
    {
        if (! is_numeric($submitted)) {
            return $this->line(null, [], null, null, 0.0);
        }

        $amount = (float) $submitted;
        $rate = self::rateForCycle(
            $link->unitPricing->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])->all(),
            $cycle
        );

        return $this->line(
            null,
            [],
            $this->normaliseAmount($submitted),
            $rate,
            round($amount * $rate, 2)
        );
    }

    /**
     * Dropdown / radio / checkbox: the selected values' rates are summed. A
     * fixed link resolves to its declared default instead of to a submission.
     */
    private function resolveDiscrete(ProductOptionGroupProduct $link, mixed $submitted, bool $editable, string $cycle): array
    {
        if (! $editable) {
            $default = self::defaultValue($link);
            $values = $default !== null ? collect([$default]) : collect();
        } else {
            $values = $this->matchValues($link, $submitted);
        }

        if ($values->isEmpty()) {
            return $this->line(null, [], null, null, 0.0);
        }

        $rates = $values->map(fn (ProductOptionLinkValue $value) => self::rateForCycle(
            $value->pricing->mapWithKeys(fn ($price) => [$price->billing_cycle => (float) $price->price_modifier])->all(),
            $cycle
        ));

        $multiple = is_array($submitted) || $values->count() > 1;

        return $this->line(
            $multiple ? null : (int) $values->first()->id,
            $values->map(fn (ProductOptionLinkValue $value) => (int) $value->id)->values()->all(),
            $multiple
                ? $values->map(fn (ProductOptionLinkValue $value) => $value->label)->values()->all()
                : $values->first()->label,
            $multiple ? null : (float) $rates->first(),
            round((float) $rates->sum(), 2)
        );
    }

    /**
     * Resolve submitted tokens to link values — by id first, then by label.
     *
     * @return Collection<int, ProductOptionLinkValue>
     */
    private function matchValues(ProductOptionGroupProduct $link, mixed $submitted): Collection
    {
        if ($submitted === null || $submitted === '' || $submitted === []) {
            return collect();
        }

        $tokens = is_array($submitted) ? $submitted : [$submitted];

        return collect($tokens)
            ->map(fn ($token) => $this->matchValue($link, $token))
            ->filter()
            ->values();
    }

    private function matchValue(ProductOptionGroupProduct $link, mixed $token): ?ProductOptionLinkValue
    {
        if (is_int($token) || (is_string($token) && ctype_digit($token))) {
            $byId = $link->linkValues->firstWhere('id', (int) $token);

            if ($byId !== null) {
                return $byId;
            }
        }

        if (! is_scalar($token)) {
            return null;
        }

        return $link->linkValues->first(fn (ProductOptionLinkValue $value) => (string) $value->label === (string) $token);
    }

    /**
     * Keep a submitted amount in its natural type for display: whole units
     * stay integers ("2 vCPU", not "2.0").
     */
    private function normaliseAmount(mixed $submitted): int|float
    {
        $amount = (float) $submitted;

        return $amount == (int) $amount ? (int) $amount : $amount;
    }

    /**
     * @param  list<int>  $valueIds
     * @return array{value_id: int|null, value_ids: list<int>, selected: mixed,
     *               price_unit: float|null, price_applied: float}
     */
    private function line(?int $valueId, array $valueIds, mixed $selected, ?float $priceUnit, float $priceApplied): array
    {
        return [
            'value_id' => $valueId,
            'value_ids' => $valueIds,
            'selected' => $selected,
            'price_unit' => $priceUnit,
            'price_applied' => $priceApplied,
        ];
    }
}
