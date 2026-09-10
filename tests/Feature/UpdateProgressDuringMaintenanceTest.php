<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The update progress endpoint has to keep answering while the update it is
 * reporting on holds the application in maintenance mode.
 *
 * The update enables maintenance mode at step 2 of 7 and generates a random
 * `--secret` it immediately discards, so before the exclusion every poll for
 * the rest of the run answered 503 and the progress bar froze at 30%.
 */
final class UpdateProgressDuringMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $role  = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $admin->roles()->syncWithoutDetaching($role);

        return $admin;
    }

    /**
     * Report the app as down without writing storage/framework/down.
     *
     * A real `artisan down` here would take the developer's site offline if the
     * test aborted before cleanup.
     */
    private function fakeMaintenanceMode(): void
    {
        $this->app->bind(MaintenanceMode::class, fn () => new class implements MaintenanceMode
        {
            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return [];
            }
        });
    }

    public function test_the_progress_endpoint_is_registered_as_maintenance_exempt(): void
    {
        $excluded = $this->app->make(PreventRequestsDuringMaintenance::class)->getExcludedPaths();

        $this->assertContains('admin/system/update/progress', $excluded);
    }

    public function test_progress_polling_still_answers_while_maintenance_mode_is_active(): void
    {
        $this->fakeMaintenanceMode();

        $this->assertTrue($this->app->isDownForMaintenance(), 'Test setup: the app should report as down.');

        $this->actingAs($this->adminUser())
            ->getJson(route('admin.system.update.progress'))
            ->assertOk()
            ->assertJsonStructure(['step', 'progress', 'message', 'done']);
    }

    public function test_other_admin_pages_are_still_blocked_during_maintenance(): void
    {
        $this->fakeMaintenanceMode();

        // The exclusion must be one endpoint, not a blanket bypass.
        $this->actingAs($this->adminUser())
            ->get(route('admin.system.index'))
            ->assertStatus(503);
    }
}
