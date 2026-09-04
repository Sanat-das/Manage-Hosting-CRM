<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard: staff internal notes must never render in the client portal.
 *
 * Internal notes are not a column — TicketService::addNote() stores them as an
 * ordinary `ticket_replies` row whose message carries the INTERNAL_NOTE_PREFIX.
 * There is no global scope filtering them, so every read path has to reject them
 * itself. The JSON API did; the client Blade view did not, and printed staff
 * deliberation verbatim to the customer it was written about.
 */
class ClientTicketInternalNoteLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_note_is_not_visible_to_the_customer(): void
    {
        $clientUser = User::create([
            'first_name' => 'Cus',
            'last_name' => 'Tomer',
            'email' => 'note-leak-client@example.test',
            'password_hash' => bcrypt('Password123'),
            'role' => 'client',
            'status' => 'active',
        ]);

        $staff = User::create([
            'first_name' => 'Sup',
            'last_name' => 'Port',
            'email' => 'note-leak-staff@example.test',
            'password_hash' => bcrypt('Password123'),
            'role' => 'support',
            'status' => 'active',
        ]);

        $customer = Customer::create([
            'user_id' => $clientUser->id,
            'company' => 'Acme Ltd',
            'status' => 'active',
        ]);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my server',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        $tickets = app(TicketService::class);
        $tickets->reply($ticket, $clientUser, 'My site is down.');

        $secret = 'SUSPECTED-CHARGEBACK-FRAUD-DO-NOT-REFUND';
        $tickets->addNote($ticket, $staff, $secret);

        $response = $this->actingAs($clientUser)
            ->get(route('client.tickets.show', $ticket->id));

        $response->assertOk();
        $response->assertSee('My site is down.');
        $response->assertDontSee($secret);
        $response->assertDontSee(TicketService::INTERNAL_NOTE_PREFIX);
    }
}
