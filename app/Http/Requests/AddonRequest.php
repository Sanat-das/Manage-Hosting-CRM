<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('products.addons') ?? false;
    }

    /**
     * The per-cycle matrix posts one `pricing[<cycle>][price|setup_fee]` group
     * plus a `pricing_cycles[]` checkbox per enabled cycle (the inputs are
     * always rendered, so the checkboxes alone decide which cycles count).
     * Normalize it into the list shape the controller writes as
     * product_addon_pricing rows; checked cycles with no values are dropped.
     */
    protected function prepareForValidation(): void
    {
        $checked = $this->input('pricing_cycles', []);
        $posted = $this->input('pricing', []);

        if (! is_array($checked)) {
            $checked = [];
        }

        if (! is_array($posted)) {
            $posted = [];
        }

        $rows = [];

        foreach ($checked as $cycle) {
            if (! is_string($cycle) || trim($cycle) === '') {
                continue;
            }

            $cycle = trim($cycle);
            $values = is_array($posted[$cycle] ?? null) ? $posted[$cycle] : [];
            $price = $values['price'] ?? null;
            $setupFee = $values['setup_fee'] ?? null;

            if (blank($price) && blank($setupFee)) {
                continue;
            }

            $rows[] = [
                'billing_cycle' => $cycle,
                'price' => $price,
                'setup_fee' => $setupFee,
            ];
        }

        $this->merge(['pricing' => $rows]);
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

            // Per-cycle pricing matrix (product_addon_pricing rows)
            'pricing' => ['sometimes', 'array', 'max:6'],
            'pricing.*.billing_cycle' => ['required', 'string', 'distinct', Rule::in(Order::BILLING_CYCLES)],
            'pricing.*.price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'pricing.*.setup_fee' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
