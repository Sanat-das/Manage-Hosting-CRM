<?php

namespace Tests\Unit;

use App\Services\Billing\BillingService;
use App\Services\OrderNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    private OrderNumberService $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = new OrderNumberService;
    }

    public function test_financial_year_label_at_march_boundary(): void
    {
        $this->assertSame('2526', OrderNumberService::financialYearLabel(CarbonImmutable::parse('2026-03-31')));
    }

    public function test_financial_year_label_at_april_boundary(): void
    {
        $this->assertSame('2627', OrderNumberService::financialYearLabel(CarbonImmutable::parse('2026-04-01')));
    }

    public function test_generate_number_matches_fy_format_and_length_cap(): void
    {
        $number = app(BillingService::class)->generateNumber();

        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $number);
        $this->assertLessThanOrEqual(16, strlen($number));
        $this->assertSame('INV-'.OrderNumberService::financialYearLabel(now()).'-00001', $number);
    }

    public function test_consecutive_generate_number_calls_are_distinct_and_consecutive(): void
    {
        $service = app(BillingService::class);

        $first = $service->generateNumber();
        $second = $service->generateNumber();

        $this->assertNotSame($first, $second, 'row lock must serialize concurrent deliveries');
        $this->assertSame(substr($first, 0, 8), substr($second, 0, 8));
        $this->assertSame((int) substr($first, -5) + 1, (int) substr($second, -5));
    }

    public function test_sequences_are_scoped_per_financial_year(): void
    {
        $march = $this->numbers->nextForFinancialYear('INV', CarbonImmutable::parse('2026-03-15'));
        $april = $this->numbers->nextForFinancialYear('INV', CarbonImmutable::parse('2026-04-15'));

        $this->assertSame('INV-2526-00001', $march);
        $this->assertSame('INV-2627-00001', $april);
    }
}
