<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Ticket::scopeVisibleTo` / `TicketService::applyVisibility` — department-scoped
 * visibility for staff, unscoped for admin. Client visibility (by customer_id)
 * is untouched by this scope and is not exercised here.
 */
class TicketVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_admin_sees_all_tickets(): void
    {
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $admin = User::factory()->create(['role' => 'admin']);

        $visible = Ticket::query()->visibleTo($admin)->get();

        $this->assertCount(2, $visible);
    }

    public function test_staff_scoped_to_one_department_sees_only_that_departments_tickets(): void
    {
        $support = $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);

        $visible = Ticket::query()->visibleTo($agent)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($support->id, $visible->first()->id);
    }

    public function test_staff_with_no_departments_sees_none(): void
    {
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = User::factory()->create(['role' => 'staff']);

        $visible = Ticket::query()->visibleTo($agent)->get();

        $this->assertCount(0, $visible);
    }

    public function test_staff_with_two_departments_sees_both(): void
    {
        $support = $this->ticketInDepartment('support');
        $billing = $this->ticketInDepartment('billing');
        $this->ticketInDepartment('sales');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);
        TicketDepartment::where('slug', 'billing')->firstOrFail()->staff()->attach($agent->id);

        $visible = Ticket::query()->visibleTo($agent)->get();

        $this->assertCount(2, $visible);
        $this->assertEqualsCanonicalizing(
            [$support->id, $billing->id],
            $visible->pluck('id')->all()
        );
    }

    public function test_apply_visibility_helper_delegates_to_the_scope(): void
    {
        $support = $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);

        $visible = TicketService::applyVisibility(Ticket::query(), $agent)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($support->id, $visible->first()->id);
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
}
