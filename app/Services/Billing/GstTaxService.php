<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\GstSetting;
use App\Models\Product;
use App\Models\Setting;

/**
 * GstTaxService — GST engine (port of Modules\Billing\InvoiceModel GST logic
 * and BillController::calculateTax).
 *
 * Business rules (all faithful to the reference — see learnings.md):
 *  - Intra-state (company state code == customer state code) → CGST + SGST split.
 *  - Inter-state → IGST only.
 *  - Tax is computed PER LINE on the item total with round(.., 2) HALF_UP, and
 *    the invoice tax is the SUM of the per-line amounts — never recomputed from
 *    the invoice total, never re-rounded.
 *  - gst_type exempt / reverse_charge → every rate and amount forced to 0.
 *  - Tax mode precedence per line: global → global rates; per_product → only if
 *    the product has gst_enabled=1, using per-product CGST/SGST/IGST when any is
 *    set (else global); mixed → product gst_enabled ? product rates : global.
 *  - Invoice totals: tax = Σ item amounts; total = amount + tax - discount
 *    (discount applied AFTER tax).
 */
class GstTaxService
{
    public const GST_TYPE_STANDARD = 'standard';

    public const GST_TYPE_EXEMPT = 'exempt';

    public const GST_TYPE_REVERSE_CHARGE = 'reverse_charge';

    /**
     * Intra-state detection — exact port of InvoiceModel L54-55.
     * Both codes must be non-empty for the comparison to decide; a null/empty
     * customer code therefore counts as inter-state here. Callers that must
     * never default to the higher IGST (renewals) resolve the customer state
     * BEFORE calling in — see BillingService::processRecurringBilling().
     */
    public static function isIntraState(string $companyStateCode, ?string $customerStateCode): bool
    {
        return $companyStateCode !== ''
            && $customerStateCode !== null
            && $customerStateCode !== ''
            && strtoupper($companyStateCode) === strtoupper($customerStateCode);
    }

    /**
     * Normalize GST settings (id=1 row, or the schema defaults when absent) into
     * a plain array the engine can read. Matches gst_settings seed: state '27',
     * cgst 9, sgst 9, igst 18, enabled 0, tax_mode 'global'.
     *
     * @return array{enabled:int,tax_mode:string,state_code:string,cgst_rate:float,sgst_rate:float,igst_rate:float}
     */
    public static function loadSettings(?GstSetting $setting = null): array
    {
        $setting ??= GstSetting::find(1);

        if (! $setting) {
            return [
                'enabled' => 0,
                'tax_mode' => 'global',
                'state_code' => '27',
                'cgst_rate' => 9.0,
                'sgst_rate' => 9.0,
                'igst_rate' => 18.0,
            ];
        }

        return [
            'enabled' => (int) ($setting->enabled ?? 0),
            'tax_mode' => (string) ($setting->tax_mode ?? 'global'),
            'state_code' => (string) ($setting->state_code ?? '27'),
            'cgst_rate' => (float) ($setting->cgst_rate ?? 9),
            'sgst_rate' => (float) ($setting->sgst_rate ?? 9),
            'igst_rate' => (float) ($setting->igst_rate ?? 18),
        ];
    }

    /**
     * Compute the GST fields for a single invoice line.
     *
     * Faithful port of InvoiceModel::createWithItems L66-196. The returned array
     * is the union of the raw item data and the per-line tax fields expected by
     * the invoice_items table (gst_enabled, gst_rate, gst_type, cgst_rate,
     * cgst_amount, sgst_rate, sgst_amount, igst_rate, igst_amount).
     *
     * @param  array  $item  Item with at least 'total'; 'product_id' optional.
     * @param  array  $settings  Normalized settings from loadSettings().
     * @param  string|null  $customerStateCode  Customer's 2-letter state code (null → inter-state per reference formula).
     */
    public static function calculateItemTax(array $item, array $settings, ?string $customerStateCode): array
    {
        $itemTotal = (float) ($item['total'] ?? 0);
        $productId = $item['product_id'] ?? null;

        $result = [
            'gst_enabled' => 0,
            'gst_rate' => null,
            'gst_type' => null,
            'cgst_rate' => null,
            'cgst_amount' => null,
            'sgst_rate' => null,
            'sgst_amount' => null,
            'igst_rate' => null,
            'igst_amount' => null,
        ];

        if (! (int) ($settings['enabled'] ?? 0)) {
            return $result;
        }

        $taxMode = (string) ($settings['tax_mode'] ?? 'global');
        $isIntraState = self::isIntraState((string) ($settings['state_code'] ?? ''), $customerStateCode);

        $globalCgstRate = (float) ($settings['cgst_rate'] ?? 9);
        $globalSgstRate = (float) ($settings['sgst_rate'] ?? 9);
        $globalIgstRate = (float) ($settings['igst_rate'] ?? 18);

        // Product-level GST settings (per_product / mixed modes).
        $productGstEnabled = 0;
        $productGstType = self::GST_TYPE_STANDARD;
        $productCgstRate = null;
        $productSgstRate = null;
        $productIgstRate = null;

        if ($productId !== null && $productId !== '') {
            $product = Product::find((int) $productId);
            if ($product) {
                $productGstEnabled = (int) ($product->gst_enabled ?? 0);
                $productGstType = (string) ($product->gst_type ?? self::GST_TYPE_STANDARD);
                $productCgstRate = $product->cgst_rate !== null ? (float) $product->cgst_rate : null;
                $productSgstRate = $product->sgst_rate !== null ? (float) $product->sgst_rate : null;
                $productIgstRate = $product->igst_rate !== null ? (float) $product->igst_rate : null;
            }
        }

        // Apply tax_mode logic (port of the reference switch).
        $applyGst = false;
        $effectiveType = self::GST_TYPE_STANDARD;
        $usePerProductRates = false;

        switch ($taxMode) {
            case 'per_product':
                if ($productGstEnabled) {
                    $applyGst = true;
                    $effectiveType = $productGstType;
                    $usePerProductRates = $productCgstRate !== null || $productSgstRate !== null || $productIgstRate !== null;
                }
                break;

            case 'mixed':
                if ($productGstEnabled) {
                    $applyGst = true;
                    $effectiveType = $productGstType;
                    $usePerProductRates = $productCgstRate !== null || $productSgstRate !== null || $productIgstRate !== null;
                } else {
                    $applyGst = true;
                }
                break;

            case 'global':
            default:
                // Global mode: global rates for every line, standard type.
                $applyGst = true;
                break;
        }

        if (! $applyGst) {
            return $result;
        }

        $result['gst_enabled'] = 1;
        $result['gst_type'] = $effectiveType;

        // Exempt / reverse_charge: all rates and amounts forced to 0.
        if ($effectiveType === self::GST_TYPE_EXEMPT || $effectiveType === self::GST_TYPE_REVERSE_CHARGE) {
            $result['gst_rate'] = 0;
            $result['cgst_rate'] = 0;
            $result['cgst_amount'] = 0;
            $result['sgst_rate'] = 0;
            $result['sgst_amount'] = 0;
            $result['igst_rate'] = 0;
            $result['igst_amount'] = 0;

            return $result;
        }

        if ($usePerProductRates) {
            if ($isIntraState) {
                $result['cgst_rate'] = $productCgstRate ?? 0;
                $result['sgst_rate'] = $productSgstRate ?? 0;
                $result['cgst_amount'] = round($itemTotal * $result['cgst_rate'] / 100, 2);
                $result['sgst_amount'] = round($itemTotal * $result['sgst_rate'] / 100, 2);
                $result['gst_rate'] = $result['cgst_rate'] + $result['sgst_rate'];
            } else {
                $result['igst_rate'] = $productIgstRate ?? 0;
                $result['igst_amount'] = round($itemTotal * $result['igst_rate'] / 100, 2);
                $result['gst_rate'] = $result['igst_rate'];
            }
        } else {
            if ($isIntraState) {
                $result['cgst_rate'] = $globalCgstRate;
                $result['sgst_rate'] = $globalSgstRate;
                $result['cgst_amount'] = round($itemTotal * $globalCgstRate / 100, 2);
                $result['sgst_amount'] = round($itemTotal * $globalSgstRate / 100, 2);
                $result['gst_rate'] = $globalCgstRate + $globalSgstRate;
            } else {
                $result['igst_rate'] = $globalIgstRate;
                $result['igst_amount'] = round($itemTotal * $globalIgstRate / 100, 2);
                $result['gst_rate'] = $globalIgstRate;
            }
        }

        return $result;
    }

    /**
     * Compute the invoice-level GST totals for a set of line items.
     *
     * Port of InvoiceModel::createWithItems L60-64 + L223-248. Per-line amounts
     * are rounded inside calculateItemTax(); the totals below are pure sums
     * (rounded only for storage, which the DECIMAL column would do anyway).
     *
     * @param  array  $items  Raw items (each with 'total').
     * @param  array  $settings  Normalized settings from loadSettings().
     * @return array{items:array<int,array>,invoice:array}
     */
    public static function computeInvoiceTaxes(array $items, array $settings, ?string $customerStateCode): array
    {
        $isIntraState = self::isIntraState((string) ($settings['state_code'] ?? ''), $customerStateCode);
        $globalCgstRate = (float) ($settings['cgst_rate'] ?? 9);
        $globalSgstRate = (float) ($settings['sgst_rate'] ?? 9);
        $globalIgstRate = (float) ($settings['igst_rate'] ?? 18);

        $totalCgst = 0.0;
        $totalSgst = 0.0;
        $totalIgst = 0.0;
        $totalTax = 0.0;
        $invoiceGstEnabled = 0;
        $taxedItems = [];

        foreach ($items as $item) {
            $tax = self::calculateItemTax($item, $settings, $customerStateCode);

            if ($tax['gst_enabled']) {
                $totalCgst += (float) $tax['cgst_amount'];
                $totalSgst += (float) $tax['sgst_amount'];
                $totalIgst += (float) $tax['igst_amount'];
                $totalTax += (float) $tax['cgst_amount'] + (float) $tax['sgst_amount'] + (float) $tax['igst_amount'];
                $invoiceGstEnabled = 1;
            }

            $taxedItems[] = array_replace($item, $tax);
        }

        return [
            'items' => $taxedItems,
            'invoice' => [
                'gst_enabled' => $invoiceGstEnabled,
                'tax' => round($totalTax, 2),
                // Invoice-level rates mirror the reference: the GLOBAL rates are
                // stored on the invoice even when per-product rates were used per line.
                'cgst_rate' => $isIntraState ? $globalCgstRate : null,
                'cgst_amount' => round($totalCgst, 2),
                'sgst_rate' => $isIntraState ? $globalSgstRate : null,
                'sgst_amount' => round($totalSgst, 2),
                'igst_rate' => $isIntraState ? null : $globalIgstRate,
                'igst_amount' => round($totalIgst, 2),
            ],
        ];
    }

    /**
     * Calculate tax for a plain amount (quote/estimate helper).
     *
     * Port of BillController::calculateTax (L434-479). The reference reads a
     * gst_settings.tax_rate column that does not exist in the schema; per
     * decisions.md #5 the port reads the seeded settings group tax_rate
     * (fallback 18). For country != 'IN' the flat tax_rate applies (no
     * CGST/SGST/IGST split).
     *
     * @return array{taxable_amount:float,tax_rate:float,cgst_rate:float,sgst_rate:float,igst_rate:float,cgst_amount:float,sgst_amount:float,igst_amount:float,total_tax:float}
     */
    public static function calculateTax(float $subtotal, string $stateCode, string $countryCode): array
    {
        $settings = self::loadSettings();

        $taxRate = (float) (Setting::where('setting_key', 'tax_rate')->value('setting_value') ?? 18);

        $cgstRate = (float) ($settings['cgst_rate'] ?? $taxRate / 2);
        $sgstRate = (float) ($settings['sgst_rate'] ?? $taxRate / 2);
        $igstRate = (float) ($settings['igst_rate'] ?? $taxRate);

        $cgstAmount = round($subtotal * $cgstRate / 100, 2);
        $sgstAmount = round($subtotal * $sgstRate / 100, 2);
        $igstAmount = round($subtotal * $igstRate / 100, 2);

        // Flat-rate tax for non-Indian customers (reference behavior).
        if (strtoupper($countryCode) !== 'IN') {
            $totalTax = round($subtotal * $taxRate / 100, 2);

            return [
                'taxable_amount' => $subtotal,
                'tax_rate' => $taxRate,
                'cgst_rate' => 0.0,
                'sgst_rate' => 0.0,
                'igst_rate' => 0.0,
                'cgst_amount' => 0.0,
                'sgst_amount' => 0.0,
                'igst_amount' => 0.0,
                'total_tax' => $totalTax,
            ];
        }

        // Intra-state → CGST + SGST; inter-state → IGST (reference L964-969).
        if (self::isIntraState((string) ($settings['state_code'] ?? ''), $stateCode)) {
            $totalTax = $cgstAmount + $sgstAmount;
            $igstAmount = 0.0;
        } else {
            $totalTax = $igstAmount;
            $cgstAmount = 0.0;
            $sgstAmount = 0.0;
        }

        return [
            'taxable_amount' => $subtotal,
            'tax_rate' => $taxRate,
            'cgst_rate' => $cgstRate,
            'sgst_rate' => $sgstRate,
            'igst_rate' => $igstRate,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'total_tax' => $totalTax,
        ];
    }
}
