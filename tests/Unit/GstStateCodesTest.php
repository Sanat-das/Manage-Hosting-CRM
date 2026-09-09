<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\GstStateCodes;
use PHPUnit\Framework\TestCase;

/**
 * The one vocabulary every state code is stored in.
 *
 * The company held numeric GST codes ('27') and customers held two-letter ones
 * ('WB'), so GstTaxService::isIntraState() — which decides CGST+SGST vs IGST by
 * comparing them — could never match, and every sale was billed inter-state.
 */
class GstStateCodesTest extends TestCase
{
    public function test_there_are_thirty_eight_codes_and_they_are_two_digit_strings(): void
    {
        $all = GstStateCodes::all();

        $this->assertCount(38, $all);

        foreach ($all as $code => $name) {
            $this->assertMatchesRegularExpression('/^\d{2}$/', (string) $code, "Code {$code} is not two digits.");
            $this->assertNotSame('', trim($name));
        }

        // PHP canonicalises '10'..'38' into integer array keys and cannot be
        // talked out of it, so the accessors that callers pass to Rule::in and
        // compare with === must hand back strings.
        foreach (GstStateCodes::codes() as $code) {
            $this->assertIsString($code, 'codes() leaked a non-string; Rule::in would reject a posted "27".');
        }

        $this->assertContains('27', GstStateCodes::codes());
        $this->assertContains('01', GstStateCodes::codes());

        // The range is contiguous 01..38 — 97 and 99 are jurisdictions, not
        // states, and are deliberately excluded.
        $this->assertSame('01', (string) array_key_first($all));
        $this->assertSame('38', (string) array_key_last($all));
        $this->assertArrayNotHasKey('97', $all);
        $this->assertArrayNotHasKey('99', $all);
    }

    public function test_the_codes_that_get_quoted_most_are_right(): void
    {
        $this->assertSame('Maharashtra', GstStateCodes::name('27'));
        $this->assertSame('West Bengal', GstStateCodes::name('19'));
        $this->assertSame('Delhi', GstStateCodes::name('07'));
        $this->assertSame('Karnataka', GstStateCodes::name('29'));
        $this->assertSame('Tamil Nadu', GstStateCodes::name('33'));
        $this->assertSame('Telangana', GstStateCodes::name('36'));
        $this->assertSame('Gujarat', GstStateCodes::name('24'));
        $this->assertSame('Odisha', GstStateCodes::name('21'));
        $this->assertSame('Chhattisgarh', GstStateCodes::name('22'));
        $this->assertSame('Ladakh', GstStateCodes::name('38'));
    }

    public function test_normalize_accepts_every_notation_the_data_actually_holds(): void
    {
        // What customers.state_code held.
        $this->assertSame('19', GstStateCodes::normalize('WB'));
        $this->assertSame('27', GstStateCodes::normalize('MH'));
        $this->assertSame('19', GstStateCodes::normalize('wb'));

        // What gst_settings.state_code held.
        $this->assertSame('27', GstStateCodes::normalize('27'));

        // Unpadded, numeric, and full names typed into the address field.
        $this->assertSame('07', GstStateCodes::normalize('7'));
        $this->assertSame('07', GstStateCodes::normalize(7));
        $this->assertSame('19', GstStateCodes::normalize('West Bengal'));
        $this->assertSame('27', GstStateCodes::normalize('  maharashtra '));
        $this->assertSame('21', GstStateCodes::normalize('Orissa'));
        $this->assertSame('34', GstStateCodes::normalize('Pondicherry'));
    }

    public function test_normalize_refuses_what_it_cannot_place(): void
    {
        // "Bengaluru" used to become "BE" through the old two-letter
        // truncation, which then quietly meant inter-state forever.
        $this->assertNull(GstStateCodes::normalize('Bengaluru'));
        $this->assertNull(GstStateCodes::normalize('BE'));
        $this->assertNull(GstStateCodes::normalize('California'));
        $this->assertNull(GstStateCodes::normalize('99'));
        $this->assertNull(GstStateCodes::normalize('00'));
        $this->assertNull(GstStateCodes::normalize('39'));
        $this->assertNull(GstStateCodes::normalize(''));
        $this->assertNull(GstStateCodes::normalize('   '));
        $this->assertNull(GstStateCodes::normalize(null));
    }

    public function test_present_day_andhra_pradesh_wins_the_alpha_code(): void
    {
        // 28 is the pre-division state and is reachable only by its number;
        // 'AP' must mean the state that exists now.
        $this->assertSame('37', GstStateCodes::normalize('AP'));
        $this->assertSame('37', GstStateCodes::normalize('Andhra Pradesh'));
        $this->assertSame('Andhra Pradesh (before division)', GstStateCodes::name('28'));
    }

    public function test_is_valid_only_accepts_canonical_codes(): void
    {
        $this->assertTrue(GstStateCodes::isValid('27'));
        $this->assertFalse(GstStateCodes::isValid('MH'));
        $this->assertFalse(GstStateCodes::isValid('7'));
        $this->assertFalse(GstStateCodes::isValid(null));
    }

    public function test_options_are_labelled_for_a_select(): void
    {
        $options = GstStateCodes::options();

        $this->assertCount(38, $options);
        $this->assertSame('27 — Maharashtra', $options['27']);
    }
}
