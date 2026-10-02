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

    /**
     * Renewal invoice generation window, in days before the due date
     * (WHMCS "Generate X days before due"). 0 = generate on the due date.
     */
    public int $renewal_invoice_days = 0;

    public ?string $bank_name = null;

    public ?string $bank_account_holder = null;

    public ?string $bank_account_no = null;

    public ?string $bank_ifsc = null;

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
            'renewal_invoice_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_holder' => ['nullable', 'string', 'max:255'],
            'bank_account_no' => ['nullable', 'string', 'max:34'],
            'bank_ifsc' => ['nullable', 'string', 'max:11'],
        ];
    }
}
