<?php

namespace App\Http\Requests;

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
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['dropdown', 'radio', 'quantity'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],

            // Option values are replaced wholesale on save (reference behavior)
            'values' => ['nullable', 'array'],
            'values.*.label' => ['required_with:values', 'string', 'max:255'],
            'values.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'values.*.pricing' => ['nullable', 'array'],
            'values.*.pricing.*.price_modifier' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
