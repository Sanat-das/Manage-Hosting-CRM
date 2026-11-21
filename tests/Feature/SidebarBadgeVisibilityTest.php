<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Services\TicketService;
use App\Support\AdminLte\NotificationBadgeFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ticket sidebar badge must count only the tickets the signed-in user can
 * see — the same visibility rule TicketController applies to its list and its
 * metric cards. Without it a department-scoped agent sees a count that does
 * not match their own page.
 */
class SidebarBadgeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    public function test_admin_badge_counts_all_open_tickets(): void
    {
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $item = $this->badgeFor('admin.tickets.index');

        $this->assertSame(2, $item['label']);
        $this->assertSame('warning', $item['label_color']);
    }

    public function test_department_scoped_agent_badge_counts_only_their_tickets(): void
    {
        $this->ticketInDepartment('support');
        $this->ticketInDepartment('billing');

        $agent = User::factory()->create(['role' => 'staff']);
        TicketDepartment::where('slug', 'support')->firstOrFail()->staff()->attach($agent->id);
        $this->actingAs($agent);

        $this->assertSame(1, $this->badgeFor('admin.tickets.index')['label']);
    }

    public function test_agent_with_no_departments_gets_no_badge(): void
    {
        $this->ticketInDepartment('support');

        $this->actingAs(User::factory()->create(['role' => 'staff']));

        $this->assertArrayNotHasKey('label', $this->badgeFor('admin.tickets.index'));
    }

    public function test_unscoped_queue_badges_are_unchanged(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff']));

        $item = $this->badgeFor('admin.provisioning-events.index');

        $this->assertArrayNotHasKey('label', $item);
        $this->assertArrayNotHasKey('label_color', $item);
    }

    /**
     * @return array<string, mixed>
     */
    private function badgeFor(string $route): array
    {
        return (new NotificationBadgeFilter)->transform(['route' => $route, 'text' => 'Queue']);
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
