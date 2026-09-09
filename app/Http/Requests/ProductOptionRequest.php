<?php

namespace App\Http\Requests;

use App\Models\ProductOptionGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for configurable option groups (product_option_groups →
 * product_option_values → product_option_pricing).
 */
class ProductOptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('products.options') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required when creating (a template nothing offers is dead
            // weight), optional when editing: the edit form does not post
            // attachments at all, because detaching there would cascade away
            // the per-product pricing. Absent = leave the attachments alone.
            'product_ids' => $this->isMethod('POST')
                ? ['required', 'array', 'min:1']
                : ['sometimes', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            // Unit of measure for the value the customer ends up with: "200"
            // means nothing on an invoice, "200 GB" is the feature. Optional —
            // discrete features (Backup: Daily / Weekly) carry their own words.
            'unit' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::in(ProductOptionGroup::OPTION_TYPES)],
            'input_min' => ['nullable', 'numeric', 'min:0'],
            'input_max' => ['nullable', 'numeric', 'min:0'],
            'input_step' => ['nullable', 'numeric', 'min:0'],
            // A checkbox group's cap on how many values may be ticked. Stored
            // in input_max (OptionSelectionRules reads it there), but posted
            // under its own name so the two meanings of that column never
            // arrive in the same request and race.
            'max_selections' => ['nullable', 'integer', 'min:1'],
            'input_placeholder' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // Option values are replaced wholesale on save (reference behavior)
            'values' => ['nullable', 'array'],
            'values.*.label' => ['required_with:values', 'string', 'max:255'],
            'values.*.is_default' => ['nullable', 'boolean'],
            'values.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'values.*.pricing' => ['nullable', 'array'],
            'values.*.pricing.*.price_modifier' => ['nullable', 'numeric', 'min:0'],

            // Which value row a product gets when the option is FIXED (the
            // customer cannot change it). Carries the row's index, not an id:
            // values are recreated on every save, so ids do not survive.
            'default_value_index' => ['nullable', 'string', 'max:20'],
        ];
    }
}
