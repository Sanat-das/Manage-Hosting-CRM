<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T18 — `POST admin/tickets/{ticket}/transfer` route + gate, delegating to
 * `TicketService::transferDepartment` (T7) behind the T16 visibility scope.
 */
class AdminTransferRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_admin_can_transfer(): void
    {
        $this->seed(AdminLteRbacSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->roles()->syncWithoutDetaching($adminRole);

        $ticket = $this->ticketInDepartment('support');

        $response = $this->actingAs($admin)
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'billing',
            ]);

        $response->assertRedirect(route('admin.tickets.show', $ticket));
        $response->assertSessionHas('success');
        $this->assertSame('billing', $ticket->fresh()->department);
    }

    public function test_without_permission_403(): void
    {
        $staff = $this->staffWithPermissions([], ['sales']);
        $ticket = $this->ticketInDepartment('sales');

        $this->actingAs($staff)
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'billing',
            ])
            ->assertForbidden();
    }

    public function test_staff_not_in_scope_gets_403(): void
    {
        $staff = $this->staffWithPermissions(['tickets.transfer'], ['sales']);
        $billing = $this->ticketInDepartment('billing');

        $this->actingAs($staff)
            ->post(route('admin.tickets.transfer', $billing), [
                'target_department' => 'support',
            ])
            ->assertForbidden();

        $this->assertSame('billing', $billing->fresh()->department);
    }

    private function ticketInDepartment(string $department): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test',
            'priority' => 'low',
            'status' => 'open',
            'department' => $department,
            'last_reply_at' => now(),
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, string>  $departments
     */
    private function staffWithPermissions(array $permissions, array $departments, string $roleName = 'sales'): User
    {
        $user = User::factory()->create(['role' => $roleName]);

        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        foreach ($permissions as $permName) {
            $permission = Permission::firstOrCreate(['name' => $permName], ['label' => $permName]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }
        $user->assignRole($roleName);

        foreach ($departments as $slug) {
            TicketDepartment::where('slug', $slug)->firstOrFail()->staff()->attach($user->id);
        }

        return $user;
    }
}
