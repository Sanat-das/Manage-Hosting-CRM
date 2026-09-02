<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Product catalog defaults (new T4.2 group: product).
 */
class ProductSettings extends Settings
{
    public string $product_sku_prefix = '';

    public bool $product_require_domain = false;

    public bool $product_enable_upgrades = true;

    public bool $product_enable_downgrades = false;

    public bool $product_allow_custom_pricing = false;

    public bool $product_trial_enabled = false;

    public int $product_trial_days = 0;

    public string $product_default_billing_cycle = 'monthly';

    public bool $product_prorated_charges = true;

    public bool $product_catalog_sync_enabled = false;

    public bool $product_approval_required = false;

    public string $product_license_key_prefix = '';

    public bool $product_show_in_order_form = true;

    public float $product_reseller_markup_percent = 0.0;

    public bool $product_gst_applicable = true;

    public bool $product_version_management = false;

    public static function group(): string
    {
        return 'product';
    }

    public static function rules(): array
    {
        return [
            'product_sku_prefix' => ['nullable', 'string', 'max:20'],
            'product_require_domain' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_enable_upgrades' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_enable_downgrades' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_allow_custom_pricing' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_trial_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'product_default_billing_cycle' => ['nullable', 'string', 'max:50'],
            'product_prorated_charges' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_catalog_sync_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_approval_required' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_license_key_prefix' => ['nullable', 'string', 'max:20'],
            'product_show_in_order_form' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_reseller_markup_percent' => ['nullable', 'numeric', 'between:0,1000'],
            'product_gst_applicable' => ['nullable', 'in:1,0,yes,no,true,false'],
            'product_version_management' => ['nullable', 'in:1,0,yes,no,true,false'],
        ];
    }
}
