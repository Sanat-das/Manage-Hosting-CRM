<?php

namespace Tests\Unit;

use App\Support\OptionNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Display formatting for a configurable option's numeric bounds.
 *
 * The decimal:2 cast is right for storage and wrong for reading: it rendered
 * every whole bound with a ".00" tail, so a slider was labelled "1.00 vCPU"
 * and an admin who typed 32 was shown "32.00" back.
 */
class OptionNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function values(): array
    {
        return [
            'whole decimal string loses its tail' => ['32.00', '32'],
            'fractional keeps only what it needs' => ['0.50', '0.5'],
            'genuine two-place value survives' => ['1.25', '1.25'],
            'whole integer is untouched' => [8, '8'],
            'whole float is untouched' => [8.0, '8'],
            'fractional float survives' => [2.5, '2.5'],
            'zero is a value, not an absence' => ['0.00', '0'],
            'null falls back to the default' => [null, ''],
            'empty string falls back to the default' => ['', ''],
            'non-numeric passes through untouched' => ['auto', 'auto'],
        ];
    }

    #[DataProvider('values')]
    public function test_it_renders_a_bound_the_way_a_person_writes_it(mixed $input, string $expected): void
    {
        $this->assertSame($expected, OptionNumber::format($input));
    }

    public function test_the_default_only_applies_to_an_absent_value(): void
    {
        // A slider min of 0 must render "0", not fall through to the caller's
        // fallback — 0 is a legitimate lower bound.
        $this->assertSame('100', OptionNumber::format(null, '100'));
        $this->assertSame('0', OptionNumber::format('0.00', '100'));
    }

    public function test_it_does_not_round_a_value_away(): void
    {
        // Four places are kept before trimming, so a step small enough to
        // matter is not silently flattened to zero.
        $this->assertSame('0.0625', OptionNumber::format('0.0625'));
    }
}
