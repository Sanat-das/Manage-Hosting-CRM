<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('products.addons') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'billing_cycle' => ['required', Rule::in(['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual'])],
            'setup_fee' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'welcome_email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
