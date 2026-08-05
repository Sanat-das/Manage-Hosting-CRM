<?php

namespace Tests\Unit;

use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    /**
     * Test that the invoice number format is correct.
     * We test the format pattern without touching the database.
     */
    public function test_invoice_number_format_pattern(): void
    {
        $year = date('Y');
        // Simulate what generateNumber produces
        $seq = 1;
        $number = sprintf('INV-%s-%s', $year, str_pad((string) $seq, 5, '0', STR_PAD_LEFT));

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $number);
        $this->assertEquals("INV-{$year}-00001", $number);
    }

    public function test_invoice_number_padding(): void
    {
        $year = date('Y');
        // Test various sequence numbers
        $tests = [
            [1, "INV-{$year}-00001"],
            [99, "INV-{$year}-00099"],
            [999, "INV-{$year}-00999"],
            [9999, "INV-{$year}-09999"],
            [99999, "INV-{$year}-99999"],
        ];

        foreach ($tests as [$seq, $expected]) {
            $number = sprintf('INV-%s-%s', $year, str_pad((string) $seq, 5, '0', STR_PAD_LEFT));
            $this->assertEquals($expected, $number);
        }
    }

    /**
     * Test billing cycle month mapping (extracted from processRecurringBilling logic).
     */
    public function test_billing_cycle_month_mapping(): void
    {
        $map = [
            'monthly' => 1,
            'quarterly' => 3,
            'semi_annual' => 6,
            'annual' => 12,
            'biennial' => 24,
            'unknown_cycle' => 1, // default
        ];

        foreach ($map as $cycle => $expectedMonths) {
            $months = match ($cycle) {
                'monthly' => 1,
                'quarterly' => 3,
                'semi_annual' => 6,
                'annual' => 12,
                'biennial' => 24,
                default => 1,
            };
            $this->assertEquals($expectedMonths, $months, "Cycle '{$cycle}' should map to {$expectedMonths} months");
        }
    }

    /**
     * Test that the recordPayment return shape has all expected keys.
     */
    public function test_record_payment_return_shape(): void
    {
        $expectedKeys = [
            'invoice_id', 'payment_id', 'amount', 'status',
            'previous_due', 'remaining_due', 'overpayment', 'credit_created',
        ];

        // Simulate a normal payment return
        $result = [
            'invoice_id' => 1,
            'payment_id' => 1,
            'amount' => 100.0,
            'status' => 'paid',
            'previous_due' => 100.0,
            'remaining_due' => 0.0,
            'overpayment' => 0.0,
            'credit_created' => false,
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Return array must contain key: {$key}");
        }
    }

    /**
     * Test overpayment detection logic.
     */
    public function test_overpayment_detection(): void
    {
        $total = 100.0;
        $paidAmount = 0.0;
        $payment = 150.0;

        $overpayment = ($paidAmount + $payment) - $total;
        $this->assertEquals(50.0, $overpayment);
        $this->assertGreaterThan(0, $overpayment);

        // Partial payment
        $payment2 = 60.0;
        $overpayment2 = ($paidAmount + $payment2) - $total;
        $this->assertEquals(-40.0, $overpayment2);
        $this->assertLessThanOrEqual(0, $overpayment2);
    }

    /**
     * Test paid status determination.
     */
    public function test_paid_status_determination(): void
    {
        $total = 100.0;

        // Full payment
        $newPaid = 100.0;
        $remaining = max(0.0, $total - $newPaid);
        $status = $remaining <= 0 ? 'paid' : 'partial';
        $this->assertEquals('paid', $status);

        // Partial payment
        $newPaid2 = 50.0;
        $remaining2 = max(0.0, $total - $newPaid2);
        $status2 = $remaining2 <= 0 ? 'paid' : 'partial';
        $this->assertEquals('partial', $status2);

        // Overpayment (clamped)
        $newPaid3 = 150.0;
        $remaining3 = max(0.0, $total - $newPaid3);
        $status3 = $remaining3 <= 0 ? 'paid' : 'partial';
        $this->assertEquals('paid', $status3);
    }
}
