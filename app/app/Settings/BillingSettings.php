<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Billing settings (legacy `settings` group: billing).
 */
class BillingSettings extends Settings
{
    public string $currency = 'INR';

    public int $invoice_next_number = 1;

    public string $invoice_prefix = 'INV-';

    public float $tax_rate = 18.0;

    public static function group(): string
    {
        return 'billing';
    }

    public static function rules(): array
    {
        return [
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_next_number' => ['nullable', 'integer', 'min:0'],
            'invoice_prefix' => ['nullable', 'string', 'max:20'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
