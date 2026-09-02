<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Inventory / stock settings (new T4.2 group: inventory).
 */
class InventorySettings extends Settings
{
    public bool $inventory_track_stock = false;

    public int $inventory_low_stock_threshold = 5;

    public bool $inventory_auto_restock = false;

    public int $inventory_restock_min_quantity = 10;

    public bool $inventory_notify_low_stock = true;

    public string $inventory_stock_unit = 'units';

    public static function group(): string
    {
        return 'inventory';
    }

    public static function rules(): array
    {
        return [
            'inventory_track_stock' => ['nullable', 'in:1,0,yes,no,true,false'],
            'inventory_low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'inventory_auto_restock' => ['nullable', 'in:1,0,yes,no,true,false'],
            'inventory_restock_min_quantity' => ['nullable', 'integer', 'min:0'],
            'inventory_notify_low_stock' => ['nullable', 'in:1,0,yes,no,true,false'],
            'inventory_stock_unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
