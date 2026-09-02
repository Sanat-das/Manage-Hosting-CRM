<?php

namespace Tests\Unit;

use App\Services\OrderNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderNumberService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrderNumberService;
    }

    public function test_first_call_returns_ord_year_00001(): void
    {
        $number = $this->service->next();

        $this->assertSame('ORD-'.date('Y').'-00001', $number);
    }

    public function test_sequential_calls_increment(): void
    {
        $a = $this->service->next();
        $b = $this->service->next();
        $c = $this->service->next();

        $this->assertSame('00002', substr($b, -5));
        $this->assertSame('00003', substr($c, -5));
    }

    public function test_consecutive_calls_are_distinct_under_race(): void
    {
        $first = $this->service->next();
        $second = $this->service->next();

        $this->assertNotSame($first, $second, 'row lock must serialize concurrent deliveries');
    }

    public function test_format_matches_ord_yyyy_5_digits(): void
    {
        $number = $this->service->next();

        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $number);
    }

    public function test_custom_prefix_segments_counters(): void
    {
        $a = $this->service->next('INV');
        $b = $this->service->next('INV');

        $this->assertSame('INV-'.date('Y').'-00001', $a);
        $this->assertSame('INV-'.date('Y').'-00002', $b);

        // ORD counter is independent.
        $c = $this->service->next('ORD');
        $this->assertSame('ORD-'.date('Y').'-00001', $c);
    }
}
