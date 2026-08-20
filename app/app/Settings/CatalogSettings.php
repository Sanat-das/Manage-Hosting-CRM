<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Catalog / storefront display settings (new T4.2 group: catalog).
 */
class CatalogSettings extends Settings
{
    public bool $catalog_show_inactive = false;

    public bool $catalog_require_domain_for_hosting = true;

    public bool $catalog_display_prices_with_tax = false;

    public bool $catalog_show_out_of_stock = true;

    public bool $catalog_allow_preorders = false;

    public string $catalog_default_sort = 'sort_order';

    public int $catalog_products_per_page = 12;

    public string $catalog_featured_product_ids = '';

    public bool $catalog_hide_addons = false;

    public int $catalog_price_precision = 2;

    public string $catalog_currency_symbol = '₹';

    public bool $catalog_show_reviews = false;

    public float $catalog_bundle_discount_default = 0.0;

    public static function group(): string
    {
        return 'catalog';
    }

    public static function rules(): array
    {
        return [
            'catalog_show_inactive' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_require_domain_for_hosting' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_display_prices_with_tax' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_show_out_of_stock' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_allow_preorders' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_default_sort' => ['nullable', 'string', 'max:50'],
            'catalog_products_per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'catalog_featured_product_ids' => ['nullable', 'string', 'max:1000'],
            'catalog_hide_addons' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_price_precision' => ['nullable', 'integer', 'between:0,4'],
            'catalog_currency_symbol' => ['nullable', 'string', 'max:10'],
            'catalog_show_reviews' => ['nullable', 'in:1,0,yes,no,true,false'],
            'catalog_bundle_discount_default' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
