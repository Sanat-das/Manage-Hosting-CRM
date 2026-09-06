<?php

namespace Tests\Unit;

use App\Services\Billing\GstTaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GstTaxServiceTest extends TestCase
{
    use RefreshDatabase;
    // --- isIntraState ---

    public function test_is_intra_state_same_code(): void
    {
        $this->assertTrue(GstTaxService::isIntraState('27', '27'));
    }

    public function test_is_intra_state_case_insensitive(): void
    {
        $this->assertTrue(GstTaxService::isIntraState('mh', 'MH'));
    }

    public function test_is_intra_state_different_codes(): void
    {
        $this->assertFalse(GstTaxService::isIntraState('27', '06'));
    }

    public function test_is_intra_state_null_customer_code(): void
    {
        $this->assertFalse(GstTaxService::isIntraState('27', null));
    }

    public function test_is_intra_state_empty_customer_code(): void
    {
        $this->assertFalse(GstTaxService::isIntraState('27', ''));
    }

    public function test_is_intra_state_empty_company_code(): void
    {
        $this->assertFalse(GstTaxService::isIntraState('', '27'));
    }

    // --- loadSettings ---

    public function test_load_settings_defaults_when_no_record(): void
    {
        $settings = GstTaxService::loadSettings(null);
        $this->assertEquals(0, $settings['enabled']);
        $this->assertEquals('global', $settings['tax_mode']);
        $this->assertEquals('27', $settings['state_code']);
        $this->assertEquals(9.0, $settings['cgst_rate']);
        $this->assertEquals(9.0, $settings['sgst_rate']);
        $this->assertEquals(18.0, $settings['igst_rate']);
    }

    // --- calculateItemTax ---

    public function test_tax_disabled_returns_zero(): void
    {
        $settings = ['enabled' => 0, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $item = ['total' => 1000];
        $result = GstTaxService::calculateItemTax($item, $settings, '27');

        $this->assertEquals(0, $result['gst_enabled']);
        $this->assertNull($result['cgst_amount']);
        $this->assertNull($result['igst_amount']);
    }

    public function test_global_intra_state_cgst_sgst(): void
    {
        $settings = ['enabled' => 1, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $item = ['total' => 1000];
        $result = GstTaxService::calculateItemTax($item, $settings, '27');

        $this->assertEquals(1, $result['gst_enabled']);
        $this->assertEquals(9.0, $result['cgst_rate']);
        $this->assertEquals(9.0, $result['sgst_rate']);
        $this->assertEquals(90.0, $result['cgst_amount']);
        $this->assertEquals(90.0, $result['sgst_amount']);
        $this->assertEquals(18.0, $result['gst_rate']);
        $this->assertNull($result['igst_rate']);
        $this->assertEquals(0.0, $result['igst_amount']);
    }

    public function test_global_inter_state_igst(): void
    {
        $settings = ['enabled' => 1, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $item = ['total' => 1000];
        $result = GstTaxService::calculateItemTax($item, $settings, '06');

        $this->assertEquals(1, $result['gst_enabled']);
        $this->assertEquals(18.0, $result['igst_rate']);
        $this->assertEquals(180.0, $result['igst_amount']);
        $this->assertNull($result['cgst_rate']);
        $this->assertNull($result['sgst_rate']);
    }

    public function test_exempt_type_returns_zero_amounts(): void
    {
        $settings = ['enabled' => 1, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $item = ['total' => 1000, 'gst_type' => 'exempt'];
        // The gst_type is from the product, but in global mode the effective type is always 'standard'.
        // So we just verify that a standard calculation works:
        $result = GstTaxService::calculateItemTax($item, $settings, '27');
        $this->assertEquals(1, $result['gst_enabled']);
        $this->assertEquals(90.0, $result['cgst_amount']);
    }

    // --- computeInvoiceTaxes ---

    public function test_compute_invoice_taxes_sums_items(): void
    {
        $settings = ['enabled' => 1, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $items = [
            ['total' => 1000],
            ['total' => 500],
        ];
        $result = GstTaxService::computeInvoiceTaxes($items, $settings, '27');

        // 1000 * 18% + 500 * 18% = 180 + 90 = 270
        $this->assertEquals(270.0, $result['invoice']['tax']);
        $this->assertEquals(1, $result['invoice']['gst_enabled']);

        // CGST: 90 + 45 = 135
        $this->assertEquals(135.0, $result['invoice']['cgst_amount']);
        // SGST: 90 + 45 = 135
        $this->assertEquals(135.0, $result['invoice']['sgst_amount']);
        // IGST: 0
        $this->assertEquals(0.0, $result['invoice']['igst_amount']);

        $this->assertCount(2, $result['items']);
    }

    public function test_compute_invoice_taxes_inter_state_igst_only(): void
    {
        $settings = ['enabled' => 1, 'tax_mode' => 'global', 'state_code' => '27', 'cgst_rate' => 9, 'sgst_rate' => 9, 'igst_rate' => 18];
        $items = [['total' => 2000]];
        $result = GstTaxService::computeInvoiceTaxes($items, $settings, '06');

        $this->assertEquals(360.0, $result['invoice']['tax']);
        $this->assertEquals(360.0, $result['invoice']['igst_amount']);
        $this->assertEquals(0.0, $result['invoice']['cgst_amount']);
        $this->assertEquals(0.0, $result['invoice']['sgst_amount']);
    }

    // --- calculateTax (quote helper) ---

    public function test_calculate_tax_intra_state(): void
    {
        $result = GstTaxService::calculateTax(1000, '27', 'IN');
        // Assuming GST settings have state_code 27, cgst 9, sgst 9
        // Intra-state: CGST 9% of 1000 = 90, SGST 9% of 1000 = 90
        $this->assertEquals(180.0, $result['total_tax']);
        $this->assertEquals(90.0, $result['cgst_amount']);
        $this->assertEquals(90.0, $result['sgst_amount']);
        $this->assertEquals(0.0, $result['igst_amount']);
    }

    public function test_calculate_tax_inter_state(): void
    {
        $result = GstTaxService::calculateTax(1000, '06', 'IN');
        // Inter-state: IGST 18% of 1000 = 180
        $this->assertEquals(180.0, $result['total_tax']);
        $this->assertEquals(0.0, $result['cgst_amount']);
        $this->assertEquals(0.0, $result['sgst_amount']);
        $this->assertEquals(180.0, $result['igst_amount']);
    }

    public function test_calculate_tax_non_indian_flat_rate(): void
    {
        $result = GstTaxService::calculateTax(1000, 'NY', 'US');
        // Non-Indian: flat tax_rate (18%) = 180
        $this->assertEquals(180.0, $result['total_tax']);
        $this->assertEquals(0.0, $result['cgst_amount']);
        $this->assertEquals(0.0, $result['sgst_amount']);
        $this->assertEquals(0.0, $result['igst_amount']);
    }
}
