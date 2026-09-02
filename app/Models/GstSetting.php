<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['gstin', 'legal_name', 'state_code', 'state_name', 'cgst_rate', 'sgst_rate', 'igst_rate', 'hsn_code', 'sac_code', 'enabled', 'tax_mode'])]
class GstSetting extends Model
{
    protected $casts = [
        'cgst_rate' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'enabled' => 'boolean',
    ];

    public const TAX_MODE_GLOBAL = 'global';

    public const TAX_MODE_PER_PRODUCT = 'per_product';

    public const TAX_MODE_MIXED = 'mixed';

    /**
     * The company GST settings are enabled.
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->enabled ?? false);
    }

    /**
     * The effective tax-mode string ('global' | 'per_product' | 'mixed').
     */
    public function taxMode(): string
    {
        return (string) ($this->tax_mode ?? self::TAX_MODE_GLOBAL);
    }

    /**
     * The configured CGST/SGST/IGST rates.
     *
     * @return array{cgst_rate:float,sgst_rate:float,igst_rate:float}
     */
    public function rates(): array
    {
        return [
            'cgst_rate' => (float) ($this->cgst_rate ?? 9),
            'sgst_rate' => (float) ($this->sgst_rate ?? 9),
            'igst_rate' => (float) ($this->igst_rate ?? 18),
        ];
    }

    /**
     * Whether a given customer state code makes a transaction intra-state
     * (company state == customer state). Same formula as GstTaxService.
     */
    public function isIntraState(?string $customerStateCode): bool
    {
        $companyStateCode = (string) ($this->state_code ?? '27');

        return $companyStateCode !== ''
            && $customerStateCode !== null
            && $customerStateCode !== ''
            && strtoupper($companyStateCode) === strtoupper($customerStateCode);
    }
}
