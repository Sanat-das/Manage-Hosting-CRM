<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T21 — department form staff assignment + signature/is_default.
 */
class DepartmentFormExtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_create_with_staff(): void
    {
        $agent = User::factory()->create(['role' => 'staff']);

        $this->actingAsSettingsAdmin()
            ->post(route('admin.ticket-departments.store'), [
                'name' => 'Escalations',
                'enabled' => 'active',
                'description' => 'Second-line support.',
                'signature' => 'Thanks,\nEscalations Team',
                'staff_ids' => [$agent->id],
            ])
            ->assertRedirect(route('admin.ticket-departments.index'))
            ->assertSessionHasNoErrors();

        $department = TicketDepartment::where('slug', 'escalations')->firstOrFail();

        $this->assertSame('Second-line support.', $department->description);
        $this->assertSame('Thanks,\nEscalations Team', $department->signature);
        $this->assertSame([$agent->id], $department->staff->pluck('id')->all());
    }

    public function test_update_is_default_clears_others(): void
    {
        $sales = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();

        $this->assertTrue($support->fresh()->is_default, 'precondition: support is the seeded default');

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $sales), [
                'name' => 'Sales',
                'enabled' => 'active',
                'is_default' => 'yes',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($sales->fresh()->is_default);
        $this->assertFalse($support->fresh()->is_default);
        $this->assertSame(1, TicketDepartment::where('is_default', true)->count());
    }

    public function test_rejects_client_as_staff(): void
    {
        $client = User::factory()->create(['role' => 'client']);
        $support = TicketDepartment::where('slug', 'support')->firstOrFail();

        $this->actingAsSettingsAdmin()
            ->put(route('admin.ticket-departments.update', $support), [
                'name' => 'Support',
                'enabled' => 'active',
                'staff_ids' => [$client->id],
            ])
            ->assertSessionHasErrors('staff_ids');

        $this->assertSame([], $support->fresh()->staff->pluck('id')->all());
    }

    private function actingAsSettingsAdmin(): self
    {
        $user = User::factory()->create(['role' => 'admin']);

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['settings.view', 'settings.manage'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $adminRole->permissions()->syncWithoutDetaching($perm->id);
        }

        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
