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
 * Client ticket create form department picker is DB-driven, not hardcoded.
 */
class ClientDepartmentFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TicketService::forgetDepartmentCache();
    }

    private function clientUser(): User
    {
        $user = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    public function test_form_lists_db_departments(): void
    {
        TicketDepartment::create([
            'name' => 'Renewals',
            'slug' => 'renewals',
            'enabled' => true,
            'sort_order' => 99,
        ]);
        TicketService::forgetDepartmentCache();

        $user = $this->clientUser();

        $response = $this->actingAs($user)->get(route('client.tickets.create'));

        $response->assertOk()
            ->assertSee('Renewals')
            ->assertDontSee('value="abuse"', false);
    }

    public function test_hardcoded_slug_rejected(): void
    {
        $user = $this->clientUser();

        $response = $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Test',
            'message' => 'Test message.',
            'department' => 'abuse',
        ]);

        $response->assertSessionHasErrors('department');
        $this->assertSame(0, Ticket::count());
    }

    public function test_valid_db_slug_creates_ticket_in_that_department(): void
    {
        TicketDepartment::create([
            'name' => 'Renewals',
            'slug' => 'renewals',
            'enabled' => true,
            'sort_order' => 99,
        ]);
        TicketService::forgetDepartmentCache();

        $user = $this->clientUser();

        $response = $this->actingAs($user)->post(route('client.tickets.store'), [
            'subject' => 'Renewal question',
            'message' => 'When does my plan renew?',
            'department' => 'renewals',
        ]);

        $ticket = Ticket::firstOrFail();

        $response->assertRedirect(route('client.tickets.show', $ticket));
        $this->assertSame('renewals', $ticket->department);
    }
}
