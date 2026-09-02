<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T17 — `Api\TicketController::index/show/stats` scoped by department for
 * staff/admin tokens via `TicketService::applyVisibility`. Client tokens stay
 * scoped by customer_id, never by department.
 */
class ApiTicketVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_staff_token_index_is_scoped_to_their_department(): void
    {
        $support = $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = $this->staffWithDepartments(['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$support->id], $ids);
    }

    public function test_admin_token_sees_all_tickets(): void
    {
        $support = $this->ticketInDepartment('support');
        $billing = $this->ticketInDepartment('billing');

        $admin = $this->staffWithDepartments([], 'admin');
        $token = $admin->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$support->id, $billing->id], $ids);
    }

    public function test_client_token_sees_only_their_own_tickets_regardless_of_department(): void
    {
        $clientUser = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $clientUser->id, 'status' => 'active']);

        $own = Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Mine',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'billing',
            'last_reply_at' => now(),
        ]);

        // Belongs to another customer, in a department the client has no
        // membership concept of — must never leak in.
        $this->ticketInDepartment('support');

        $token = $clientUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$own->id], $ids);

        // Direct show of the out-of-scope ticket must 403.
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets/'.Ticket::where('department', 'support')->first()->id)
            ->assertForbidden();
    }

    public function test_stats_endpoint_is_scoped_for_staff(): void
    {
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = $this->staffWithDepartments(['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets/stats');

        $response->assertOk();
        $this->assertSame(2, array_sum($response->json('data')));
    }

    public function test_staff_show_of_out_of_department_ticket_is_forbidden(): void
    {
        $billing = $this->ticketInDepartment('billing');

        $agent = $this->staffWithDepartments(['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/tickets/'.$billing->id)
            ->assertForbidden();
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
     * @param  array<int, string>  $departments
     */
    private function staffWithDepartments(array $departments, string $roleName = 'support'): User
    {
        $user = User::factory()->create(['role' => $roleName]);

        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);
        $permission = Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'View Tickets']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->assignRole($roleName);

        foreach ($departments as $slug) {
            TicketDepartment::where('slug', $slug)->firstOrFail()->staff()->attach($user->id);
        }

        return $user;
    }
}
