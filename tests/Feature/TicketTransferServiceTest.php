<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T7 — TicketService::transferDepartment — first-class transfer with audit.
 *
 * Acceptance: 7 cases in DB::transaction:
 * (a) happy transfer moves department + audit + note,
 * (b) with assignTo in target sets assigned_to,
 * (c) clears when current assignee not in target,
 * (d) closed throws, (e) same throws, (f) assignTo not in target throws, (g) disabled throws.
 */
class TicketTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TicketService::forgetDepartmentCache();
    }

    public function test_transfers_department(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'email' => 'actor@example.test']);
        $ticket = $this->makeTicket('support', 'open');

        $service = app(TicketService::class);
        $result = $service->transferDepartment($ticket, 'billing', $actor, null, null);

        $this->assertSame('billing', $result->department);
        $this->assertSame('open', $result->status);
        $this->assertSame('billing', $ticket->fresh()->department);

        // Audit row
        $this->assertDatabaseHas('ticket_transfers', [
            'ticket_id' => $ticket->id,
            'from_department' => 'support',
            'to_department' => 'billing',
            'actor_id' => $actor->id,
        ]);
        $transfer = TicketTransfer::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('support', $transfer->from_department);
        $this->assertSame('billing', $transfer->to_department);
        $this->assertNull($transfer->assigned_to);
        $this->assertNull($transfer->assigned_from);
        $this->assertNull($transfer->note);

        // Internal note: [INTERNAL] [TRANSFER] support -> billing by actor@example.test
        $this->assertDatabaseHas('ticket_replies', [
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'is_staff' => 1,
        ]);
        $note = $ticket->fresh()->replies()->where('is_staff', true)->latest('id')->first();
        $this->assertStringContainsString('[INTERNAL]', $note->message);
        $this->assertStringContainsString('[TRANSFER] support -> billing by actor@example.test', $note->message);
        // No note suffix when null
        $this->assertStringNotContainsString(' — ', $note->message);
    }

    public function test_transfer_with_assign_to_in_target_sets_assigned(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'email' => 'actor2@example.test']);
        $billingDept = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $staffInBilling = User::factory()->create(['role' => 'support']);
        $billingDept->staff()->attach($staffInBilling->id);

        $ticket = $this->makeTicket('support', 'open');

        $result = app(TicketService::class)->transferDepartment($ticket, 'billing', $actor, $staffInBilling->id, 'Escalating');

        $this->assertSame('billing', $result->department);
        $this->assertSame($staffInBilling->id, $result->assigned_to);
        $this->assertSame($staffInBilling->id, $result->fresh()->assigned_to);

        $transfer = TicketTransfer::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame($staffInBilling->id, $transfer->assigned_to);
        $this->assertSame('Escalating', $transfer->note);

        $note = $ticket->fresh()->replies()->where('is_staff', true)->latest('id')->first();
        $this->assertStringContainsString(' — Escalating', $note->message);
        $this->assertStringContainsString('[TRANSFER] support -> billing by actor2@example.test', $note->message);
    }

    public function test_transfer_clears_assignee_when_not_in_target(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'email' => 'actor3@example.test']);
        $supportDept = TicketDepartment::where('slug', 'support')->firstOrFail();
        $billingDept = TicketDepartment::where('slug', 'billing')->firstOrFail();

        // Staffer only in support, not in billing
        $supportOnly = User::factory()->create(['role' => 'support']);
        $supportDept->staff()->attach($supportOnly->id);
        // Ensure not in billing
        $this->assertFalse($supportOnly->fresh()->ticketDepartments()->where('slug', 'billing')->exists());

        $ticket = $this->makeTicket('support', 'open', $supportOnly->id);

        $result = app(TicketService::class)->transferDepartment($ticket, 'billing', $actor, null, null);

        $this->assertSame('billing', $result->department);
        $this->assertNull($result->assigned_to, 'assignee not in target should be cleared to null when no assignTo provided');
        $this->assertNull($result->fresh()->assigned_to);

        $transfer = TicketTransfer::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertNull($transfer->assigned_to);
        $this->assertSame($supportOnly->id, $transfer->assigned_from);
    }

    public function test_transfer_clears_assignee_and_sets_to_assign_to_when_provided(): void
    {
        $actor = User::factory()->create(['role' => 'admin', 'email' => 'actor4@example.test']);
        $supportDept = TicketDepartment::where('slug', 'support')->firstOrFail();
        $billingDept = TicketDepartment::where('slug', 'billing')->firstOrFail();

        $supportOnly = User::factory()->create(['role' => 'support']);
        $supportDept->staff()->attach($supportOnly->id);

        $billingStaff = User::factory()->create(['role' => 'support']);
        $billingDept->staff()->attach($billingStaff->id);

        $ticket = $this->makeTicket('support', 'open', $supportOnly->id);

        $result = app(TicketService::class)->transferDepartment($ticket, 'billing', $actor, $billingStaff->id, null);

        $this->assertSame('billing', $result->department);
        $this->assertSame($billingStaff->id, $result->assigned_to);
    }

    public function test_rejects_closed_ticket(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support', 'closed');

        try {
            app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Reopen before transfer', $e->getMessage());
        }

        // Transaction rollback: no audit, no department change
        $this->assertSame('support', $ticket->fresh()->department);
        $this->assertDatabaseMissing('ticket_transfers', ['ticket_id' => $ticket->id]);
        $this->assertCount(0, $ticket->fresh()->replies()->where('is_staff', true)->where('message', 'like', '%[TRANSFER]%')->get());
    }

    public function test_rejects_same_department(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support', 'open');

        try {
            app(TicketService::class)->transferDepartment($ticket, 'support', $actor);
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('already in that department', $e->getMessage());
        }

        $this->assertSame('support', $ticket->fresh()->department);
        $this->assertDatabaseMissing('ticket_transfers', ['ticket_id' => $ticket->id]);
    }

    public function test_rejects_assign_to_not_in_target(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support', 'open');

        // Staff only in support, not billing
        $supportDept = TicketDepartment::where('slug', 'support')->firstOrFail();
        $outsider = User::factory()->create(['role' => 'support']);
        $supportDept->staff()->attach($outsider->id);

        try {
            app(TicketService::class)->transferDepartment($ticket, 'billing', $actor, $outsider->id);
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Assignee not in target department', $e->getMessage());
        }

        $this->assertSame('support', $ticket->fresh()->department);
        $this->assertDatabaseMissing('ticket_transfers', ['ticket_id' => $ticket->id]);
    }

    public function test_rejects_disabled_target(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support', 'open');

        $billing = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $billing->update(['enabled' => false]);
        TicketService::forgetDepartmentCache();

        try {
            app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Target department not found or disabled', $e->getMessage());
        }

        $this->assertSame('support', $ticket->fresh()->department);
        $this->assertDatabaseMissing('ticket_transfers', ['ticket_id' => $ticket->id]);
    }

    public function test_keeps_status_open_when_transferring_from_pending(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ticket = $this->makeTicket('support', 'answered');

        $result = app(TicketService::class)->transferDepartment($ticket, 'billing', $actor);

        $this->assertSame('open', $result->status);
        $this->assertSame('open', $result->fresh()->status);
    }

    private function makeTicket(string $department, string $status = 'open', ?int $assignedTo = null): Ticket
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        return Ticket::create([
            'ticket_no' => 'TKT-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'subject' => 'Test ticket',
            'priority' => 'medium',
            'status' => $status,
            'department' => $department,
            'assigned_to' => $assignedTo,
            'last_reply_at' => now(),
        ]);
    }
}
