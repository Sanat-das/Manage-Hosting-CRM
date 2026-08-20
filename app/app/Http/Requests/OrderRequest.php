<?php

namespace App\Http\Requests;

use App\Models\Order;
use App\Models\Product;
use App\Support\OptionSelectionRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create-order validation (mirrors the customer pilot's store rules).
 *
 * Accepts the multi-line payload from the admin order form (lines[] — one
 * entry per product, each with its own billing cycle / quantity / unit price
 * / option selections) AND the legacy single-product payload (top-level
 * product_id / billing_cycle / quantity / unit_price), which is normalized
 * into lines[0] so every entry point shares one code path.
 *
 * - customer must exist AND be active (inactive/terminated customers are
 *   not orderable — matches the create-form dropdown which filters to
 *   active customers)
 * - each line's product must exist AND be active (only active products are
 *   orderable)
 * - billing cycle per line from the orders/products vocabulary, and — when
 *   the product has a pricing ladder — restricted to the cycles that ladder
 *   actually offers (matching the form's cycle dropdown)
 * - quantity per line capped by the shared Order::MAX_QUANTITY; single-unit
 *   products (quantity_behaviour = none) are locked to quantity 1
 * - unit_price is admin-entered; the server computes total = price * qty
 * - domain_name validates as a hostname when present, and is REQUIRED when
 *   the primary line's product has require_domain set
 * - option selections per line validated by the shared per-type rules
 * - IPs are NOT captured here: products requiring an IP get their lease from
 *   the IPAM pool at order activation (provisioning), not on the order form
 */
class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize legacy payloads into the multi-line shape:
     *
     *  - legacy single-product payloads (top-level product_id, billing_cycle,
     *    quantity, unit_price, price_override, domain_name) become lines[0];
     *  - any callers still posting a top-level domain_name (the pre-per-line
     *    form, admin cart, API) get it mapped onto lines[0] so the per-line
     *    domain rules stay the single source of truth.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('lines') && $this->has('product_id')) {
            $this->merge([
                'lines' => [[
                    'product_id' => $this->input('product_id'),
                    'billing_cycle' => $this->input('billing_cycle'),
                    'quantity' => $this->input('quantity', 1),
                    'unit_price' => $this->input('unit_price'),
                    'override' => $this->boolean('price_override'),
                    'domain_name' => $this->input('domain_name'),
                ]],
            ]);

            return;
        }

        if ($this->has('lines') && $this->has('domain_name') && empty($this->input('lines.0.domain_name'))) {
            $lines = $this->input('lines');
            $lines[0]['domain_name'] = $this->input('domain_name');
            $this->merge(['lines' => $lines]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('status', 'active')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in([Order::STATUS_PENDING, Order::STATUS_ACTIVE])],
            'payment_method' => ['nullable', 'string', 'max:50'],
            // Post-order actions (checkboxes on the admin order form).
            'send_confirmation' => ['sometimes', 'boolean'],
            'generate_invoice' => ['sometimes', 'boolean'],
            'send_invoice' => ['sometimes', 'boolean'],

            'lines' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('status', 'active')],
            'lines.*.billing_cycle' => ['required', Rule::in(Order::BILLING_CYCLES)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:'.Order::MAX_QUANTITY],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'lines.*.override' => ['sometimes', 'boolean'],
            // ASCII hostname per line (the domain the service is provisioned
            // against): ≥2 labels, labels of a–z0–9–, ≤253 chars.
            'lines.*.domain_name' => ['nullable', 'string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i'],
        ];

        // Per-line configurable-option rules, keyed under each line's prefix.
        foreach ($this->input('lines', []) as $index => $line) {
            $product = $line['product_id'] ?? null ? Product::query()->find($line['product_id']) : null;
            if ($product === null) {
                continue;
            }

            $links = $product->optionLinks()
                ->with(['group', 'linkValues'])
                ->where('customer_editable', true)
                ->get();

            $rules = array_merge($rules, OptionSelectionRules::forLinks($links, "lines.$index.options"));
        }

        return $rules;
    }

    /**
     * Product-dependent rules that can't live in rules() because they need
     * the selected product rows:
     * - a product with a pricing ladder can only be ordered on a cycle that
     *   ladder offers (legacy products without ladder rows keep the full
     *   vocabulary)
     * - single-unit products (quantity_behaviour = none) are sold one unit
     *   at a time
     * - the catalog-price guard: unless the line ticks "custom amount", the
     *   unit price must match the product's pricing-ladder row for the
     *   chosen cycle (annual must cost the annual price, not the monthly
     *   default). Products without a ladder row for that cycle keep the
     *   legacy free-form behavior (expected === null).
     * - require_domain on ANY line requires that line's domain_name (each
     *   purchased service is provisioned against its own domain; the first
     *   line's domain also becomes the order's domain_name)
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ($this->input('lines', []) as $index => $line) {
                $product = $line['product_id'] ?? null ? Product::query()->find($line['product_id']) : null;
                if ($product === null) {
                    continue;
                }

                $ladderCycles = $product->pricing()->pluck('billing_cycle')->all();

                $allowedCycles = $ladderCycles !== []
                    ? array_values(array_intersect($ladderCycles, Order::BILLING_CYCLES))
                    : Order::BILLING_CYCLES;

                // Degenerate ladder (only free/triennial rows): fall back to
                // the full vocabulary so such products stay orderable.
                if ($allowedCycles === []) {
                    $allowedCycles = Order::BILLING_CYCLES;
                }

                if (! in_array($line['billing_cycle'] ?? null, $allowedCycles, true)) {
                    $validator->errors()->add("lines.$index.billing_cycle", 'The selected billing cycle is not available for this product.');
                }

                if ($product->isSingleUnit() && (int) ($line['quantity'] ?? 0) !== 1) {
                    $validator->errors()->add("lines.$index.quantity", 'This product is sold as a single unit only.');
                }

                if ($product->require_domain && blank($line['domain_name'] ?? null)) {
                    $validator->errors()->add("lines.$index.domain_name", 'This product requires a domain name.');
                }

                if (! ($line['override'] ?? false)) {
                    $expected = $product->pricing()
                        ->where('billing_cycle', $line['billing_cycle'] ?? null)
                        ->first()?->price;

                    if ($expected !== null && abs(round((float) ($line['unit_price'] ?? 0), 2) - (float) $expected) > 0.004) {
                        $validator->errors()->add(
                            "lines.$index.unit_price",
                            'The unit price does not match the catalog price (₹'.number_format((float) $expected, 2).') for the selected billing cycle. Tick "Custom amount" to override.'
                        );
                    }
                }
            }
        });
    }
}
