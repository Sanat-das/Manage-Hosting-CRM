<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-order validation (mirrors the customer pilot's store rules).
 *
 * - customer must exist
 * - product must exist AND be active (only active products are orderable)
 * - billing cycle from the orders/products vocabulary
 * - unit_price is admin-entered; the server computes total = price * qty
 */
class OrderRequest extends FormRequest
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
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('status', 'active')],
            'billing_cycle' => ['required', Rule::in(Order::BILLING_CYCLES)],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'domain_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
