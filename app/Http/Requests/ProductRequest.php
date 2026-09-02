<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionGroupProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for product create/update (admin + shared with the API layer
 * when convenient). Covers the product row plus the nested multi-cycle
 * pricing payload.
 */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST') ? 'products.create' : 'products.edit';

        return $this->user()?->hasPermission($permission) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'description' => ['nullable', 'string'],
            'billing_cycle' => ['required', Rule::in(array_keys(Product::DEFAULT_CYCLES))],
            'payment_type' => ['nullable', Rule::in(['free', 'one_time', 'recurring'])],
            'quantity_behaviour' => ['nullable', Rule::in(['none', 'multiple_services', 'scaling'])],
            'recurring_cycles_limit' => ['nullable', 'integer', 'min:0'],
            'auto_terminate_value' => ['nullable', 'integer', 'min:0'],
            'auto_terminate_unit' => ['nullable', Rule::in(['days', 'months', 'years'])],
            'prorata_enabled' => ['sometimes', 'boolean'],
            'prorata_date' => ['nullable', 'integer', 'between:1,28'],
            'prorata_charge_next_month' => ['sometimes', 'boolean'],
            'early_renewal_mode' => ['nullable', Rule::in(['default', 'custom'])],
            'early_renewal_days' => ['nullable', 'array'],
            'early_renewal_days.*' => ['nullable', 'integer', 'between:0,365'],
            'provisioning_module' => ['required', Rule::in(array_keys(Product::PROVISIONING_MODULES))],
            'server_group_id' => ['nullable', 'integer', 'exists:server_groups,id'],
            'welcome_email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'require_domain' => ['sometimes', 'boolean'],
            'require_public_ip' => ['sometimes', 'boolean'],
            'require_private_ip' => ['sometimes', 'boolean'],
            'show_in_order' => ['sometimes', 'boolean'],
            'show_in_affiliate' => ['sometimes', 'boolean'],
            'only_admin' => ['sometimes', 'boolean'],
            'is_bundle' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'gst_enabled' => ['sometimes', 'boolean'],
            'gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gst_type' => ['required', Rule::in(array_keys(Product::GST_TYPES))],
            'cgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sgst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'igst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // Multi-cycle pricing (product_pricing rows)
            'pricing' => ['nullable', 'array'],
            'pricing.*.setup_fee' => ['nullable', 'numeric', 'min:0'],
            'pricing.*.price' => ['nullable', 'numeric', 'min:0'],
            'pricing.*.promo_price' => ['nullable', 'numeric', 'min:0'],
            'pricing.*.promo_start' => ['nullable', 'date'],
            'pricing.*.promo_end' => ['nullable', 'date', 'after_or_equal:pricing.*.promo_start'],

            // Option groups attached at creation time, keyed by group id:
            // continuous groups carry per-cycle unit pricing, discrete groups
            // per-value pricing keyed by the GROUP value id (link rows don't
            // exist client-side yet). Unchecked groups submit nothing.
            'option_groups' => ['nullable', 'array'],
            'option_groups.*.selected' => ['sometimes', 'boolean'],
            'option_groups.*.customer_editable' => ['nullable', 'boolean'],
            'option_groups.*.unit_pricing' => ['nullable', 'array'],
            'option_groups.*.unit_pricing.*' => ['nullable', 'numeric'],
            'option_groups.*.pricing' => ['nullable', 'array'],
            'option_groups.*.pricing.*' => ['array'],
            'option_groups.*.pricing.*.*' => ['nullable', 'numeric'],
            'option_groups.*.override_defaults' => ['nullable', 'boolean'],
            'option_groups.*.input_min' => ['nullable', 'numeric', 'min:0'],
            'option_groups.*.input_max' => ['nullable', 'numeric', 'min:0'],
            'option_groups.*.input_step' => ['nullable', 'numeric', 'min:0'],
            'option_groups.*.input_placeholder' => ['nullable', 'string', 'max:255'],

            // Option links updated with the product form (edit page), keyed by
            // link id: continuous groups submit unit pricing, discrete groups
            // the wholesale values + per-value pricing replacement, plus the
            // customer_editable flag and input overrides for both.
            'option_links' => ['nullable', 'array'],
            'option_links.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'option_links.*.customer_editable' => ['nullable', 'boolean'],
            'option_links.*.values' => ['nullable', 'array'],
            'option_links.*.values.*.label' => ['nullable', 'string', 'max:100'],
            'option_links.*.values.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'option_links.*.values.*.is_default' => ['nullable', 'boolean'],
            'option_links.*.pricing' => ['nullable', 'array'],
            'option_links.*.pricing.*' => ['array'],
            'option_links.*.pricing.*.*' => ['nullable', 'numeric'],
            'option_links.*.default_value_id' => ['nullable', 'integer'],
            'option_links.*.unit_pricing' => ['nullable', 'array'],
            'option_links.*.unit_pricing.*' => ['nullable', 'numeric'],
            'option_links.*.override_defaults' => ['nullable', 'boolean'],
            'option_links.*.input_min' => ['nullable', 'numeric', 'min:0'],
            'option_links.*.input_max' => ['nullable', 'numeric', 'min:0'],
            'option_links.*.input_step' => ['nullable', 'numeric', 'min:0'],
            'option_links.*.input_placeholder' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOptionGroupPayloads($validator);
            $this->validateOptionLinkPayloads($validator);
            $this->validateBillingConfiguration($validator);
        });
    }

    /**
     * Cross-field billing guards: prorated billing needs a day of month, and
     * product-specific early-renewal windows must be keyed by a valid cycle.
     */
    private function validateBillingConfiguration(Validator $validator): void
    {
        if (filter_var($this->input('prorata_enabled'), FILTER_VALIDATE_BOOLEAN) && ! filled($this->input('prorata_date'))) {
            $validator->errors()->add('prorata_date', 'A prorata date is required when prorata billing is enabled.');
        }

        if ($this->input('early_renewal_mode') !== 'custom') {
            return;
        }

        $validCycles = ['monthly', 'quarterly', 'semi_annual', 'annual', 'biennial', 'triennial'];

        foreach (array_keys($this->input('early_renewal_days') ?? []) as $cycle) {
            if (! in_array($cycle, $validCycles, true)) {
                $validator->errors()->add(
                    "early_renewal_days.{$cycle}",
                    "The billing cycle \"{$cycle}\" is not supported."
                );
            }
        }
    }

    /**
     * Billing-cycle / range guards for the create-time option-group payloads.
     */
    private function validateOptionGroupPayloads(Validator $validator): void
    {
        $optionGroups = $this->input('option_groups');

        if (! is_array($optionGroups)) {
            return;
        }

        $validCycles = array_keys(Product::BILLING_CYCLES);

        foreach ($optionGroups as $groupId => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            if (filter_var($payload['override_defaults'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $min = $payload['input_min'] ?? null;
                $max = $payload['input_max'] ?? null;

                if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float) $min >= (float) $max) {
                    $validator->errors()->add(
                        "option_groups.{$groupId}.input_max",
                        'Max value must be greater than the min value.'
                    );
                }
            }

            foreach (array_keys($payload['unit_pricing'] ?? []) as $cycle) {
                if (! in_array($cycle, $validCycles, true)) {
                    $validator->errors()->add(
                        "option_groups.{$groupId}.unit_pricing.{$cycle}",
                        "The billing cycle \"{$cycle}\" is not supported."
                    );
                }
            }

            foreach (($payload['pricing'] ?? []) as $valueId => $cyclesByValue) {
                if (! is_array($cyclesByValue)) {
                    continue;
                }

                foreach (array_keys($cyclesByValue) as $cycle) {
                    if (! in_array($cycle, $validCycles, true)) {
                        $validator->errors()->add(
                            "option_groups.{$groupId}.pricing.{$valueId}.{$cycle}",
                            "The billing cycle \"{$cycle}\" is not supported."
                        );
                    }
                }
            }
        }
    }

    /**
     * Guards for the per-link option payloads submitted with the product
     * update form: billing-cycle keys, override range, and the discrete-group
     * default-value invariants.
     */
    private function validateOptionLinkPayloads(Validator $validator): void
    {
        $optionLinks = $this->input('option_links');

        if (! is_array($optionLinks)) {
            return;
        }

        $validCycles = array_keys(Product::BILLING_CYCLES);

        foreach ($optionLinks as $linkId => $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $link = $this->resolveLink((int) $linkId);
            $isContinuous = $link !== null && ProductOptionGroup::isContinuousType($link->group?->type);

            if (filter_var($payload['override_defaults'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $min = $payload['input_min'] ?? null;
                $max = $payload['input_max'] ?? null;

                if ($min !== null && $min !== '' && $max !== null && $max !== '' && (float) $min >= (float) $max) {
                    $validator->errors()->add(
                        "option_links.{$linkId}.input_max",
                        'Max value must be greater than the min value.'
                    );
                }
            }

            if ($isContinuous) {
                foreach (array_keys($payload['unit_pricing'] ?? []) as $cycle) {
                    if (! in_array($cycle, $validCycles, true)) {
                        $validator->errors()->add(
                            "option_links.{$linkId}.unit_pricing.{$cycle}",
                            "The billing cycle \"{$cycle}\" is not supported."
                        );
                    }
                }

                continue;
            }

            foreach (($payload['pricing'] ?? []) as $valueId => $cyclesByValue) {
                if (! is_array($cyclesByValue)) {
                    continue;
                }

                foreach (array_keys($cyclesByValue) as $cycle) {
                    if (! in_array($cycle, $validCycles, true)) {
                        $validator->errors()->add(
                            "option_links.{$linkId}.pricing.{$valueId}.{$cycle}",
                            "The billing cycle \"{$cycle}\" is not supported."
                        );
                    }
                }
            }

            $this->validateLinkDefaults(
                $validator,
                $link,
                (int) $linkId,
                array_key_exists('values', $payload) ? $payload['values'] : null
            );
        }
    }

    /**
     * A discrete link's submitted values must keep a single default, and the
     * current default may not be deleted without designating a replacement.
     * Skipped entirely when no values payload is present (e.g. a partial
     * customer-editable-only update).
     */
    private function validateLinkDefaults(Validator $validator, ?ProductOptionGroupProduct $link, int $linkId, ?array $values): void
    {
        if ($link === null || $values === null) {
            return;
        }

        $defaultCount = 0;
        $keptIds = [];
        $hasNewDefault = false;

        foreach ($values as $value) {
            if (! is_array($value)) {
                continue;
            }

            if (filter_var($value['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $defaultCount++;
                $hasNewDefault = true;
            }

            if (isset($value['id']) && is_numeric($value['id'])) {
                $keptIds[] = (int) $value['id'];
            }
        }

        if ($defaultCount > 1) {
            $validator->errors()->add("option_links.{$linkId}.values", 'Only one value may be marked as the default.');
        }

        $currentDefaultId = $link->linkValues()->where('is_default', true)->value('id');

        if ($currentDefaultId !== null && ! in_array((int) $currentDefaultId, $keptIds, true) && ! $hasNewDefault) {
            $validator->errors()->add(
                "option_links.{$linkId}.values",
                'The default value may not be deleted. Choose a new default first.'
            );
        }
    }

    /**
     * Resolve a submitted option link (with its group) for per-link guards.
     */
    private function resolveLink(int $linkId): ?ProductOptionGroupProduct
    {
        return ProductOptionGroupProduct::query()->with('group')->find($linkId);
    }
}
