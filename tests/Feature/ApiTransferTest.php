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
 * T22 — `Api\TicketController::transfer`. Mirrors the admin transfer (T18):
 * same validation/logic, gated by `tickets.transfer` for staff tokens on top
 * of the T17 visibility scope.
 */
class ApiTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_staff_can_transfer_via_api(): void
    {
        $ticket = $this->ticketInDepartment('support');
        $agent = $this->staffWithPermissions(['tickets.view', 'tickets.transfer'], ['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/transfer", [
                'target_department' => 'billing',
            ]);

        $response->assertOk();
        $this->assertSame('billing', $response->json('data.department'));
        $this->assertSame('billing', $ticket->fresh()->department);
    }

    public function test_transfer_without_permission_is_forbidden(): void
    {
        $ticket = $this->ticketInDepartment('support');
        $agent = $this->staffWithPermissions(['tickets.view'], ['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/transfer", [
                'target_department' => 'billing',
            ]);

        $response->assertForbidden();
        $this->assertSame('support', $ticket->fresh()->department);
    }

    public function test_invalid_target_department_returns_422(): void
    {
        $ticket = $this->ticketInDepartment('support');
        $agent = $this->staffWithPermissions(['tickets.view', 'tickets.transfer'], ['support']);
        $token = $agent->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/transfer", [
                'target_department' => 'not-a-real-department',
            ]);

        $response->assertStatus(422);
    }

    public function test_client_token_cannot_transfer(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Mine',
            'priority' => 'low',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/transfer", [
                'target_department' => 'billing',
            ]);

        $response->assertForbidden();
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
    private function staffWithPermissions(array $permissions, array $departments, string $roleName = 'support'): User
    {
        $user = User::factory()->create(['role' => $roleName]);

        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole($roleName);

        foreach ($departments as $slug) {
            TicketDepartment::where('slug', $slug)->firstOrFail()->staff()->attach($user->id);
        }

        return $user;
    }
}
