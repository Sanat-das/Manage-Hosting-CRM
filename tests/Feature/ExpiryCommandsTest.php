<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inventory warranty + license expiry scheduled checks.
 *
 * Mirrors the ssl:check-expiry cases in QueueSchedulerTest: the warranty
 * command only reports (inventory_assets has no expiry column), while the
 * license command reports, marks past-due rows expired, and flags seat
 * exhaustion.
 */
class ExpiryCommandsTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-'.$this->assetSequence,
            'asset_type' => 'server',
            'status' => 'in_stock',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLicense(array $overrides = []): License
    {
        $asset = $this->makeAsset();

        return License::create(array_merge([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'windows',
            'license_key' => 'KEY-'.$asset->id,
            'seats' => 5,
            'seats_available' => 5,
            'status' => 'active',
        ], $overrides));
    }

    // ---------------------------------------------------------- warranty

    public function test_warranty_command_reports_window_and_excludes_retired_and_disposed(): void
    {
        $this->makeAsset([
            'asset_tag' => 'AST-SOON',
            'warranty_expiry' => now()->addDays(10)->toDateString(),
        ]);
        $this->makeAsset([
            'asset_tag' => 'AST-FAR',
            'warranty_expiry' => now()->addDays(60)->toDateString(),
        ]);
        $this->makeAsset([
            'asset_tag' => 'AST-RETIRED',
            'status' => 'retired',
            'warranty_expiry' => now()->addDays(5)->toDateString(),
        ]);
        $this->makeAsset([
            'asset_tag' => 'AST-DISPOSED',
            'status' => 'disposed',
            'warranty_expiry' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('inventory:check-warranty-expiry', ['--days' => 30])
            ->expectsOutputToContain('AST-SOON')
            ->doesntExpectOutputToContain('AST-FAR')
            ->doesntExpectOutputToContain('AST-RETIRED')
            ->doesntExpectOutputToContain('AST-DISPOSED')
            ->assertExitCode(0);

        // Reporting must not mutate the row.
        $this->assertDatabaseHas('inventory_assets', ['asset_tag' => 'AST-SOON', 'status' => 'in_stock']);
    }

    public function test_warranty_command_reports_expired_count_excluding_retired_and_disposed(): void
    {
        $this->makeAsset([
            'asset_tag' => 'AST-EXPIRED-LIVE',
            'warranty_expiry' => now()->subDays(3)->toDateString(),
        ]);
        $this->makeAsset([
            'asset_tag' => 'AST-EXPIRED-RETIRED',
            'status' => 'retired',
            'warranty_expiry' => now()->subDays(3)->toDateString(),
        ]);
        $this->makeAsset([
            'asset_tag' => 'AST-EXPIRED-DISPOSED',
            'status' => 'disposed',
            'warranty_expiry' => now()->subDays(3)->toDateString(),
        ]);

        $this->artisan('inventory:check-warranty-expiry', ['--days' => 30])
            ->expectsOutputToContain('1 inventory assets already have an expired warranty.')
            ->assertExitCode(0);
    }

    // ----------------------------------------------------------- license

    public function test_license_command_marks_past_due_active_licenses_expired(): void
    {
        $pastDue = $this->makeLicense([
            'license_key' => 'KEY-PAST',
            'expiry_date' => now()->subDay()->toDateString(),
        ]);
        $valid = $this->makeLicense([
            'license_key' => 'KEY-VALID',
            'expiry_date' => now()->addDays(60)->toDateString(),
        ]);

        $this->artisan('licenses:check-expiry', ['--days' => 30])
            ->assertExitCode(0);

        $this->assertDatabaseHas('licenses', ['id' => $pastDue->id, 'status' => 'expired']);
        $this->assertDatabaseHas('licenses', ['id' => $valid->id, 'status' => 'active']);
    }

    public function test_license_command_reports_active_licenses_expiring_within_window(): void
    {
        $soon = $this->makeLicense([
            'license_key' => 'KEY-SOON',
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        $this->artisan('licenses:check-expiry', ['--days' => 30])
            ->expectsOutputToContain('KEY-SOON')
            ->assertExitCode(0);

        // A license inside the window is reported but not marked expired.
        $this->assertDatabaseHas('licenses', ['id' => $soon->id, 'status' => 'active']);
    }

    public function test_license_command_reports_license_expiring_today_without_marking_it_expired(): void
    {
        $today = $this->makeLicense([
            'license_key' => 'KEY-TODAY',
            'expiry_date' => today()->toDateString(),
        ]);

        $this->artisan('licenses:check-expiry', ['--days' => 30])
            ->expectsOutputToContain('KEY-TODAY')
            ->assertExitCode(0);

        // Expiring today is inside the report window (>= today) but is not yet
        // past due (< today), so the row must stay active.
        $this->assertDatabaseHas('licenses', ['id' => $today->id, 'status' => 'active']);
    }

    public function test_license_command_leaves_revoked_and_pending_licenses_untouched(): void
    {
        $revoked = $this->makeLicense([
            'license_key' => 'KEY-REVOKED',
            'status' => 'revoked',
            'expiry_date' => now()->subDays(5)->toDateString(),
        ]);
        $pending = $this->makeLicense([
            'license_key' => 'KEY-PENDING',
            'status' => 'pending',
            'expiry_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->artisan('licenses:check-expiry', ['--days' => 30])
            ->doesntExpectOutputToContain('KEY-REVOKED')
            ->doesntExpectOutputToContain('KEY-PENDING')
            ->assertExitCode(0);

        // Reporting and past-due marking both filter status = active, so
        // revoked / pending rows keep their status.
        $this->assertDatabaseHas('licenses', ['id' => $revoked->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('licenses', ['id' => $pending->id, 'status' => 'pending']);
    }

    public function test_license_command_reports_seat_exhaustion(): void
    {
        $this->makeLicense([
            'license_key' => 'KEY-NOSEATS',
            'seats_available' => 0,
        ]);
        $this->makeLicense([
            'license_key' => 'KEY-HASSEATS',
            'seats_available' => 3,
        ]);

        $this->artisan('licenses:check-expiry', ['--days' => 30])
            ->expectsOutputToContain('Found 1 active licenses with no seats available.')
            ->expectsOutputToContain('KEY-NOSEATS')
            ->doesntExpectOutputToContain('KEY-HASSEATS')
            ->assertExitCode(0);
    }

    // -------------------------------------------------- scheduler wiring

    public function test_expiry_commands_are_registered_in_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('inventory:check-warranty-expiry')
            ->expectsOutputToContain('licenses:check-expiry')
            ->assertExitCode(0);
    }
}
