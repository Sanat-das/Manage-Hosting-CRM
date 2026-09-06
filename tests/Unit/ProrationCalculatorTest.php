<?php

namespace Tests\Unit;

use App\Services\Billing\ProrationCalculator;
use Tests\TestCase;

class ProrationCalculatorTest extends TestCase
{
    // --- calculateProration ---

    public function test_upgrade_full_charge(): void
    {
        // 30-day period, change on day 10: 20 days remaining
        $result = ProrationCalculator::calculateProration(
            currentAmount: 100.0,
            newAmount: 200.0,
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            changeDate: '2026-01-11',
            changeType: 'upgrade',
        );

        // credit = 100 * 20/30 = 66.67
        $this->assertEquals(66.67, $result['credit']);
        // upgrade → charge = newAmount (full)
        $this->assertEquals(200.0, $result['charge']);
        $this->assertEquals(20, $result['proration_days']);
    }

    public function test_downgrade_prorated_charge(): void
    {
        // 30-day period, change on day 10: 20 days remaining
        $result = ProrationCalculator::calculateProration(
            currentAmount: 200.0,
            newAmount: 100.0,
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            changeDate: '2026-01-11',
            changeType: 'downgrade',
        );

        // credit = 200 * 20/30 = 133.33
        $this->assertEquals(133.33, $result['credit']);
        // downgrade → charge = 100 * 20/30 = 66.67
        $this->assertEquals(66.67, $result['charge']);
        $this->assertEquals(20, $result['proration_days']);
    }

    public function test_zero_period_days(): void
    {
        $result = ProrationCalculator::calculateProration(
            currentAmount: 100.0,
            newAmount: 200.0,
            startDate: '2026-01-01',
            endDate: '2026-01-01',
            changeDate: '2026-01-01',
            changeType: 'upgrade',
        );

        $this->assertEquals(0.0, $result['credit']);
        $this->assertEquals(200.0, $result['charge']);
        $this->assertEquals(0, $result['proration_days']);
    }

    public function test_change_on_last_day(): void
    {
        // 30-day period, change on day 30 (last day): 0 remaining
        $result = ProrationCalculator::calculateProration(
            currentAmount: 100.0,
            newAmount: 150.0,
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            changeDate: '2026-01-31',
            changeType: 'upgrade',
        );

        $this->assertEquals(0.0, $result['credit']);
        $this->assertEquals(150.0, $result['charge']);
        $this->assertEquals(0, $result['proration_days']);
    }

    public function test_change_on_first_day(): void
    {
        // 30-day period, change on day 2: 28 days remaining
        // (Jan 1→31 is 30 total, Jan 1→2 is 1 used, so 29 remaining)
        $result = ProrationCalculator::calculateProration(
            currentAmount: 100.0,
            newAmount: 150.0,
            startDate: '2026-01-01',
            endDate: '2026-01-31',
            changeDate: '2026-01-02',
            changeType: 'downgrade',
        );

        // credit = 100 * 29/30 = 96.67
        $this->assertEquals(96.67, $result['credit']);
        // charge = 150 * 29/30 = 145.0
        $this->assertEquals(145.0, $result['charge']);
        $this->assertEquals(29, $result['proration_days']);
    }

    // --- annualToMonthly ---

    public function test_annual_to_monthly(): void
    {
        $result = ProrationCalculator::annualToMonthly(
            annualAmount: 1200.0,
            startDate: '2026-01-01',
            endDate: '2026-12-31',
            changeDate: '2026-07-01',
        );

        // monthlyAmount = 1200 / 12 = 100
        // 365 total days, 181 used, 184 remaining
        // credit = 1200 * 184/365 = 605.48 (rounded)
        $this->assertIsFloat($result['credit']);
        $this->assertGreaterThan(0, $result['credit']);
        // monthly_charge = 100 * 184/365 = 50.41 (rounded, downgrade)
        $this->assertIsFloat($result['monthly_charge']);
        $this->assertGreaterThan(0, $result['monthly_charge']);
        $this->assertGreaterThan(0, $result['proration_days']);
    }
}
