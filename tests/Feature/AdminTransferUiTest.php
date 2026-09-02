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
 * T20 — Admin ticket show: "Transfer department" modal + history timeline,
 * built on top of the T18 `transfer` route/controller method.
 */
class AdminTransferUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_transfer_modal_renders_when_permitted(): void
    {
        $staff = $this->staffWithPermissions(['tickets.transfer'], ['support']);
        $ticket = $this->ticketInDepartment('support');

        $response = $this->actingAs($staff)->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('Transfer department');
        $response->assertSee('id="transfer-modal"', false);
    }

    public function test_transfer_modal_hidden_without_permission(): void
    {
        $staff = $this->staffWithPermissions([], ['support']);
        $ticket = $this->ticketInDepartment('support');

        $response = $this->actingAs($staff)->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee('Transfer department');
    }

    public function test_transfer_moves_ticket_and_show_renders_history(): void
    {
        $admin = $this->admin();
        $ticket = $this->ticketInDepartment('support');

        $this->actingAs($admin)
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'billing',
                'note' => 'Escalating to billing.',
            ])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $this->assertSame('billing', $ticket->fresh()->department);

        $response = $this->actingAs($admin)->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('Transfer History');
        $response->assertSee($admin->full_name);
        $response->assertSee('Escalating to billing.');
    }

    public function test_assign_to_outside_target_department_shows_validation_error(): void
    {
        $admin = $this->admin();
        $ticket = $this->ticketInDepartment('support');
        $billingOnlyStaff = $this->staffWithPermissions([], ['billing'], 'staff');

        $response = $this->actingAs($admin)
            ->from(route('admin.tickets.show', $ticket))
            ->post(route('admin.tickets.transfer', $ticket), [
                'target_department' => 'technical',
                'assigned_to' => $billingOnlyStaff->id,
            ]);

        $response->assertRedirect(route('admin.tickets.show', $ticket));
        $response->assertSessionHasErrors('error');
        $this->assertSame('support', $ticket->fresh()->department);
    }

    private function admin(): User
    {
        $this->seed(AdminLteRbacSeeder::class);
        $adminRole = Role::where('name', 'admin')->firstOrFail();

        $admin = User::factory()->create(['role' => 'admin']);
        $admin->roles()->syncWithoutDetaching($adminRole);

        return $admin;
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
        if (Role::where('name', 'admin')->doesntExist()) {
            $this->seed(AdminLteRbacSeeder::class);
        }

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
