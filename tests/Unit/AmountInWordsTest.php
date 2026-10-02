<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\TestCase;

/**
 * The exact wording the invoice PDF prints, in Indian numbering (lakh, crore).
 *
 * The paise branch is the fragile one: 5.10 as a float is 5.0999... and a
 * naive floor(5.1 * 100) spells "Nine Paise". The converter rounds the scaled
 * fraction instead, and these tests pin that behaviour so a refactor cannot
 * quietly re-introduce the classic float bug.
 */
class AmountInWordsTest extends TestCase
{
    public function test_zero_whole_rupees_and_small_paise(): void
    {
        $this->assertSame('Rupees Zero Only', AmountInWords::convert(0));
        $this->assertSame('Rupees One Only', AmountInWords::convert(1.00));
        $this->assertSame('Rupees One and Fifty Paise Only', AmountInWords::convert(1.50));
        $this->assertSame('Rupees Zero and Five Paise Only', AmountInWords::convert(0.05));
    }

    public function test_thousand_lakh_and_crore_boundaries(): void
    {
        $this->assertSame('Rupees One Thousand and One Paise Only', AmountInWords::convert(1000.01));
        $this->assertSame('Rupees One Thousand Nine Only', AmountInWords::convert(1009));
        $this->assertSame('Rupees One Lakh Only', AmountInWords::convert(100000));
        $this->assertSame('Rupees One Crore Only', AmountInWords::convert(10000000));
    }

    public function test_full_spell_out_at_every_scale(): void
    {
        $this->assertSame('Rupees Twenty Only', AmountInWords::convert(20));
        $this->assertSame('Rupees One Hundred Only', AmountInWords::convert(100));
        $this->assertSame(
            'Rupees One Lakh Twenty Three Thousand Four Hundred Fifty Six and Seventy Eight Paise Only',
            AmountInWords::convert(123456.78),
        );
        $this->assertSame(
            'Rupees Nine Crore Ninety Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine and Ninety Nine Paise Only',
            AmountInWords::convert(99999999.99),
        );
    }

    public function test_and_only_for_whole_rupees_with_hundreds_and_tens(): void
    {
        $this->assertSame('Rupees Five Hundred and Ninety Eight Only', AmountInWords::convert(598.00));
        $this->assertSame('Rupees One Thousand Two Hundred and Thirty Four Only', AmountInWords::convert(1234.00));
        $this->assertSame('Rupees One Lakh Two Hundred and Thirty Four Only', AmountInWords::convert(100234.00));
        $this->assertSame('Rupees Five Hundred Only', AmountInWords::convert(500.00));
        $this->assertSame('Rupees Fifty Eight Only', AmountInWords::convert(58.00));
        $this->assertSame('Rupees Five Hundred Ninety Eight and Fifty Paise Only', AmountInWords::convert(598.50));
    }

    public function test_large_odd_values_carry_every_group(): void
    {
        $this->assertSame(
            'Rupees Ninety Eight Lakh Seventy Six Thousand Five Hundred Forty Three and Twenty One Paise Only',
            AmountInWords::convert(9876543.21),
        );
        $this->assertSame(
            'Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight and Ninety Paise Only',
            AmountInWords::convert(12345678.90),
        );
    }

    public function test_float_precision_trap_rounds_the_scaled_paise(): void
    {
        // 5.1 as a float is 5.0999...; 0.0999 * 100 floors to 9 without rounding.
        $this->assertSame('Rupees Five and Ten Paise Only', AmountInWords::convert(5.10));
        $this->assertSame('Rupees Five and Nine Paise Only', AmountInWords::convert(5.09));
    }

    public function test_negative_amounts_spell_as_their_absolute_value(): void
    {
        $this->assertSame('Rupees One and Fifty Paise Only', AmountInWords::convert(-1.50));
    }
}
