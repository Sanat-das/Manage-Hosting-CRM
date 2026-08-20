<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for attaching an option group to a product (creates the
 * `product_option_group_product` pivot row).
 */
class ProductOptionLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'option_group_id' => ['required', 'exists:product_option_groups,id'],
            'customer_editable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'option_group_id.required' => 'An option group is required.',
            'option_group_id.exists' => 'The selected option group does not exist.',
            'customer_editable.boolean' => 'The customer editable flag must be true or false.',
        ];
    }
}
