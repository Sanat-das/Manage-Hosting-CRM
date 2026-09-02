<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use App\Models\ProductOptionLinkValue;
use App\Models\ProductOptionLinkValuePricing;

/**
 * Product-scoped option-link assembly.
 *
 * Owns the three mutations that touch a product's option links:
 * - attach: snapshot a catalog group's values + per-cycle pricing onto a
 *   product (`product_option_group_product` + `product_option_link_value` rows),
 * - unit pricing: wholesale per-cycle replacement for continuous groups,
 * - per-value pricing: wholesale per-cycle replacement for a link value.
 *
 * Shared by the admin attach/update flow (ProductOptionLinkController) and the
 * product create flow (ProductController::store), where the option-group
 * payload is submitted keyed by group id before any link rows exist.
 */
class ProductOptionLinkService
{
    public function attachGroup(Product $product, ProductOptionGroup $group, bool $customerEditable = false): ProductOptionGroupProduct
    {
        $link = ProductOptionGroupProduct::create([
            'product_id' => $product->id,
            'option_group_id' => $group->id,
            'customer_editable' => $customerEditable,
            // New attachments append at the end of the display order.
            'sort_order' => (int) $product->optionLinks()->max('sort_order') + 1,
        ]);

        $this->copyGroupValues($link);

        return $link;
    }

    /**
     * Copy the group's catalog values and their per-cycle pricing into the
     * product-scoped snapshot tables. The first value becomes the default.
     */
    public function copyGroupValues(ProductOptionGroupProduct $link): void
    {
        $values = $link->group->values()
            ->with('pricing')
            ->orderBy('sort_order')
            ->get();

        foreach ($values as $index => $value) {
            $linkValue = ProductOptionLinkValue::create([
                'product_option_group_product_id' => $link->id,
                'label' => $value->label,
                'is_default' => $index === 0,
                'sort_order' => $value->sort_order,
            ]);

            foreach ($value->pricing as $pricing) {
                ProductOptionLinkValuePricing::create([
                    'product_option_link_value_id' => $linkValue->id,
                    'billing_cycle' => $pricing->billing_cycle,
                    'price_modifier' => $pricing->price_modifier,
                ]);
            }
        }
    }

    /**
     * Wholesale re-snapshot the product's link values from the catalog group:
     * existing snapshot values (and their pricing) are replaced by the group's
     * current values, so the product re-tracks the group. Per-product unit
     * pricing and customer_editable are untouched.
     */
    public function syncValuesFromGroup(ProductOptionGroupProduct $link): void
    {
        $link->linkValues()->delete(); // FK cascade removes the pricing rows

        $this->copyGroupValues($link);
    }

    /**
     * Replace the link's unit pricing rows wholesale (continuous types only).
     *
     * @param  array<string, mixed>  $unitPricing  billing_cycle => unit price modifier
     */
    public function saveUnitPricing(ProductOptionGroupProduct $link, array $unitPricing): void
    {
        $link->unitPricing()->delete();

        foreach ($unitPricing as $cycle => $modifier) {
            if ($modifier === null || $modifier === '') {
                continue;
            }

            $link->unitPricing()->create([
                'billing_cycle' => $cycle,
                'price_modifier' => $modifier,
            ]);
        }
    }

    /**
     * Replace a link value's pricing rows wholesale (delete-then-insert).
     *
     * @param  array<string, mixed>  $cycleModifiers
     */
    public function saveLinkValuePricing(ProductOptionLinkValue $linkValue, array $cycleModifiers): void
    {
        $linkValue->pricing()->delete();

        foreach ($cycleModifiers as $cycle => $modifier) {
            if ($modifier === null || $modifier === '') {
                continue;
            }

            $linkValue->pricing()->create([
                'billing_cycle' => $cycle,
                'price_modifier' => $modifier,
            ]);
        }
    }

    /**
     * Apply per-value pricing submitted against GROUP value ids — the create
     * flow, where no link rows exist client-side yet.
     *
     * copyGroupValues() creates the link values in sort_order alignment with
     * the group's values, so the submitted cycles are mapped onto the matching
     * link values by position.
     *
     * @param  array<string, array<string, mixed>>  $pricingByGroupValueId  group value id => billing_cycle => modifier
     */
    public function applyGroupValuePricing(ProductOptionGroupProduct $link, array $pricingByGroupValueId): void
    {
        if ($pricingByGroupValueId === []) {
            return;
        }

        $groupValues = $link->group->values()->orderBy('sort_order')->get();
        $linkValues = $link->linkValues()->orderBy('sort_order')->get();

        foreach ($groupValues as $index => $groupValue) {
            $cycles = $pricingByGroupValueId[$groupValue->id] ?? null;

            if (! is_array($cycles) || ! isset($linkValues[$index])) {
                continue;
            }

            $this->saveLinkValuePricing($linkValues[$index], $cycles);
        }
    }

    /**
     * Save (or clear) per-product overrides of the group's Min / Max / Step /
     * Placeholder. A null override means "inherit the catalog group's value".
     *
     * @param  array<string, mixed>  $payload  override_defaults + input_min/max/step/placeholder
     */
    public function saveInputOverrides(ProductOptionGroupProduct $link, array $payload): void
    {
        $override = filter_var($payload['override_defaults'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $link->update([
            'input_min' => $this->overrideValue($payload, $override, 'input_min'),
            'input_max' => $this->overrideValue($payload, $override, 'input_max'),
            'input_step' => $this->overrideValue($payload, $override, 'input_step'),
            'input_placeholder' => $this->overrideValue($payload, $override, 'input_placeholder'),
        ]);
    }

    /**
     * The override value to persist for a field: null unless the override
     * switch is on, and empty strings are normalized to null (inherit).
     *
     * @param  array<string, mixed>  $payload
     */
    private function overrideValue(array $payload, bool $override, string $field): mixed
    {
        if (! $override) {
            return null;
        }

        $value = $payload[$field] ?? null;

        return $value === '' ? null : $value;
    }

    /**
     * Apply a per-product option-link update payload: customer_editable, the
     * Min / Max / Step / Placeholder overrides, and then either wholesale unit
     * pricing (continuous groups) or the wholesale values + per-value pricing
     * replacement (discrete groups). Legacy values on continuous groups are
     * never touched.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateLink(ProductOptionGroupProduct $link, array $payload): void
    {
        $link->update([
            'customer_editable' => (bool) ($payload['customer_editable'] ?? false),
            'sort_order' => isset($payload['sort_order']) ? (int) $payload['sort_order'] : $link->sort_order,
        ]);

        $this->saveInputOverrides($link, $payload);

        if (ProductOptionGroup::isContinuousType($link->group?->type)) {
            $this->saveUnitPricing($link, $payload['unit_pricing'] ?? []);

            return;
        }

        $flaggedDefaultId = $this->saveLinkValues($link, $payload['values'] ?? [], $payload['pricing'] ?? []);
        $this->applyDefaultValue($link, $payload['default_value_id'] ?? null, $flaggedDefaultId);
    }

    /**
     * Bulk-replace the link's values (mirrors ProductOptionController::saveValues
     * but preserves existing rows by id): entries carrying an id update the
     * matching row, entries without an id are created, and existing rows absent
     * from the payload are deleted — never the current default (the request
     * already rejects deleting it without a replacement).
     *
     * @param  array<int, array<string, mixed>>  $values
     * @param  array<string, array<string, mixed>>  $pricing
     */
    public function saveLinkValues(ProductOptionGroupProduct $link, array $values, array $pricing): ?int
    {
        $currentDefaultId = $link->linkValues()->where('is_default', true)->value('id');

        $keptIds = [];
        foreach ($values as $value) {
            if (isset($value['id']) && is_numeric($value['id'])) {
                $keptIds[] = (int) $value['id'];
            }
        }

        // Delete stale rows, keeping the current default alive (the request
        // guarantees a replacement default exists before it can be removed).
        $deleteQuery = $link->linkValues()->whereNotIn('id', $keptIds ?: [0]);
        if ($currentDefaultId !== null) {
            $deleteQuery->where('id', '!=', (int) $currentDefaultId);
        }
        $deleteQuery->delete();

        $flaggedDefaultId = null;

        foreach ($values as $value) {
            if (empty($value['label'])) {
                continue;
            }

            $data = [
                'label' => $value['label'],
                'sort_order' => (int) ($value['sort_order'] ?? 0),
                'is_default' => (bool) ($value['is_default'] ?? false),
            ];

            $valueId = isset($value['id']) && is_numeric($value['id']) ? (int) $value['id'] : null;
            $linkValue = $valueId !== null ? $link->linkValues()->whereKey($valueId)->first() : null;

            if ($linkValue !== null) {
                $linkValue->update($data);
            } else {
                $linkValue = $link->linkValues()->create($data);
            }

            if ($data['is_default']) {
                $flaggedDefaultId = $linkValue->id;
            }

            $this->saveLinkValuePricing($linkValue, $pricing[$linkValue->id] ?? []);
        }

        return $flaggedDefaultId;
    }

    /**
     * Enforce a single default: when a default is designated (explicitly via
     * default_value_id or implicitly by a value flagged is_default), clear the
     * flag on every other link value and set it on the designated one.
     */
    public function applyDefaultValue(ProductOptionGroupProduct $link, ?int $defaultValueId, ?int $flaggedDefaultId): void
    {
        $designatedId = $defaultValueId ?? $flaggedDefaultId;

        if ($designatedId === null) {
            return;
        }

        $link->linkValues()->update(['is_default' => false]);
        $link->linkValues()->whereKey($designatedId)->update(['is_default' => true]);
    }
}
