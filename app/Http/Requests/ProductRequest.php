<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'type' => ['required', Rule::in(array_keys(Product::TYPES))],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'description' => ['nullable', 'string'],
            'billing_cycle' => ['required', Rule::in(array_keys(Product::DEFAULT_CYCLES))],
            'provisioning_module' => ['required', Rule::in(array_keys(Product::PROVISIONING_MODULES))],
            'server_group_id' => ['nullable', 'integer', 'exists:server_groups,id'],
            'welcome_email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'require_domain' => ['sometimes', 'boolean'],
            'show_in_order' => ['sometimes', 'boolean'],
            'show_in_affiliate' => ['sometimes', 'boolean'],
            'only_admin' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'quota_disk' => ['nullable', 'integer', 'min:0'],
            'quota_bandwidth' => ['nullable', 'integer', 'min:0'],
            'quota_email' => ['nullable', 'integer', 'min:0'],
            'quota_database' => ['nullable', 'integer', 'min:0'],
            'quota_cpu_cores' => ['nullable', 'integer', 'min:0'],
            'quota_cpu_speed' => ['nullable', 'integer', 'min:0'],
            'quota_ram' => ['nullable', 'integer', 'min:0'],
            'quota_ips' => ['nullable', 'integer', 'min:0'],
            'quota_ftp_accounts' => ['nullable', 'integer', 'min:0'],
            'quota_subdomains' => ['nullable', 'integer', 'min:0'],
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
        ];
    }
}
