<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\License;
use App\Models\LicenseAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Seat accounting is self-healing.
 *
 * licenses.seats_available is derived (seats minus assignments with
 * released_at IS NULL) but was only written at creation, so assignment writes
 * and seats edits could leave it stale or inverted. These cases pin the model
 * events, the admin update path and the licenses:reconcile-seats sweep.
 */
class LicenseSeatReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $assetSequence = 0;

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create();

        // Seed the admin role and license permissions in the test DB.
        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $view = Permission::firstOrCreate(['name' => 'licenses.view'], ['label' => 'View Licenses']);
        $manage = Permission::firstOrCreate(['name' => 'licenses.manage'], ['label' => 'Manage Licenses']);
        $adminRole->permissions()->syncWithoutDetaching([$view->id, $manage->id]);

        $user->assignRole('admin');

        return $this->actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSoftwareLicenseAsset(array $overrides = []): InventoryAsset
    {
        $this->assetSequence++;

        return InventoryAsset::create(array_merge([
            'asset_tag' => 'AST-SEAT-'.$this->assetSequence,
            'asset_type' => 'software_license',
            'status' => 'in_stock',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeLicense(array $overrides = []): License
    {
        $asset = $this->makeSoftwareLicenseAsset();

        return License::create(array_merge([
            'inventory_asset_id' => $asset->id,
            'license_type' => 'cpanel',
            'license_key' => 'KEY-'.$asset->id,
            'seats' => 5,
            'seats_available' => 5,
            'status' => 'active',
        ], $overrides));
    }

    private function assignSeat(License $license, int $assignedToId): LicenseAssignment
    {
        return LicenseAssignment::create([
            'license_id' => $license->id,
            'assigned_to_type' => 'service',
            'assigned_to_id' => $assignedToId,
            'assigned_at' => now(),
        ]);
    }

    public function test_creating_an_assignment_decrements_seats_available(): void
    {
        $license = $this->makeLicense();

        $this->assignSeat($license, 1);

        $this->assertSame(4, $license->fresh()->seats_available);
    }

    public function test_releasing_an_assignment_restores_seats_available(): void
    {
        $license = $this->makeLicense();
        $assignment = $this->assignSeat($license, 1);

        $this->assertSame(4, $license->fresh()->seats_available);

        $assignment->update(['released_at' => now()]);

        $this->assertSame(5, $license->fresh()->seats_available);
    }

    public function test_deleting_an_assignment_restores_seats_available(): void
    {
        $license = $this->makeLicense();
        $assignment = $this->assignSeat($license, 1);

        $this->assertSame(4, $license->fresh()->seats_available);

        $assignment->delete();

        $this->assertSame(5, $license->fresh()->seats_available);
    }

    public function test_updating_seats_via_the_admin_endpoint_recomputes_available_without_inverting(): void
    {
        $license = $this->makeLicense(['seats' => 5, 'seats_available' => 5]);
        $this->assignSeat($license, 1);
        $this->assignSeat($license, 2);

        // Two active assignments against five seats leaves three.
        $this->assertSame(3, $license->fresh()->seats_available);

        $this->actingAsAdmin()
            ->put("/admin/licenses/{$license->id}", ['seats' => 8])
            ->assertRedirect(route('admin.licenses.show', $license));

        $this->assertSame(6, $license->fresh()->seats_available);

        // Dropping seats below the active assignment count must floor at zero,
        // never invert the invariant (seats_available > seats).
        $this->actingAsAdmin()
            ->put("/admin/licenses/{$license->id}", ['seats' => 1]);

        $fresh = $license->fresh();
        $this->assertSame(1, $fresh->seats);
        $this->assertSame(0, $fresh->seats_available);
    }

    public function test_command_heals_a_drifted_seats_available(): void
    {
        $license = $this->makeLicense(['seats' => 5, 'seats_available' => 5]);
        $this->assignSeat($license, 1);

        // Force drift behind the model events: pretend the counter is wrong.
        DB::table('licenses')->where('id', $license->id)->update(['seats_available' => 99]);

        $this->artisan('licenses:reconcile-seats')
            ->expectsOutputToContain($license->license_key)
            ->assertExitCode(0);

        $this->assertSame(4, $license->fresh()->seats_available);
    }

    public function test_dry_run_reports_drift_without_writing(): void
    {
        $license = $this->makeLicense(['seats' => 5, 'seats_available' => 5]);
        $this->assignSeat($license, 1);

        DB::table('licenses')->where('id', $license->id)->update(['seats_available' => 99]);

        $this->artisan('licenses:reconcile-seats', ['--dry-run' => true])
            ->expectsOutputToContain($license->license_key.': 99 → 4')
            ->assertExitCode(0);

        $this->assertSame(
            99,
            (int) DB::table('licenses')->where('id', $license->id)->value('seats_available'),
        );
    }
}
