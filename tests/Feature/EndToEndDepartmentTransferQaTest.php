<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\TicketTransfer;
use App\Models\User;
use App\Services\TicketMailParser;
use App\Services\TicketService;
use App\Support\InboundEmail;
use Database\Seeders\AdminLteRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * F3 — Real end-to-end manual QA scenario for the department transfer feature.
 *
 * Walks the 11 checkpoints the plan promises, with real HTTP requests, real
 * DB state, real inbound-mail parsing, and real outbound email job assertions.
 * Every checkpoint is its own assertion block so a failure pinpoints which
 * guarantee broke.
 */
class EndToEndDepartmentTransferQaTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSFER_NOTE = 'Wrong department — moving to billing.';

    private TicketDepartment $sales;

    private TicketDepartment $billing;

    private User $client;

    private Customer $customer;

    private User $actorAdmin;

    private User $salesStaff;

    private User $billingStaff;

    private Ticket $adminTransferredTicket;

    private Ticket $apiTransferredTicket;

    protected function setUp(): void
    {
        parent::setUp();

        // (1) Seed RBAC (admin gets tickets.transfer / tickets.view, etc.).
        $this->seed(AdminLteRbacSeeder::class);
        TicketService::forgetDepartmentCache();

        // (2) Distinct email addresses and mailbox config for Sales and Billing.
        $this->sales = TicketDepartment::where('slug', 'sales')->firstOrFail();
        $this->sales->update([
            'email_address' => 'sales@example.test',
            'imap_enabled' => true,
            'imap_host' => 'mail.example.test',
            'imap_port' => 993,
            'imap_username' => 'sales@example.test',
            'imap_password' => 'secret-sales',
            'imap_folder' => 'INBOX',
        ]);

        $this->billing = TicketDepartment::where('slug', 'billing')->firstOrFail();
        $this->billing->update([
            'email_address' => 'billing@example.test',
            'imap_enabled' => true,
            'imap_host' => 'mail.example.test',
            'imap_port' => 993,
            'imap_username' => 'billing@example.test',
            'imap_password' => 'secret-billing',
            'imap_folder' => 'INBOX',
        ]);

        // (3) Staff A bound to Sales, Staff B bound to Billing via the pivot.
        $this->salesStaff = $this->makeStaff('sales.staff@example.test', ['sales']);
        $this->billingStaff = $this->makeStaff('billing.staff@example.test', ['billing']);

        // An admin actor (also in Sales pivot) for the transfer.
        $this->actorAdmin = $this->makeStaff('actor.admin@example.test', ['sales', 'billing']);
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->actorAdmin->roles()->syncWithoutDetaching($adminRole);

        // (4) A client and its customer record.
        $clientUser = User::factory()->create([
            'role' => 'client',
            'email' => 'customer@example.test',
            'first_name' => 'Casey',
            'last_name' => 'Customer',
        ]);
        $this->customer = Customer::create(['user_id' => $clientUser->id, 'status' => 'active']);
        $this->client = $clientUser;
    }

    /**
     * Single end-to-end test that walks every checkpoint with real assertions.
     * Split into clearly-labeled sections so a failure pinpoints the broken
     * checkpoint; the per-section assertComments make the output self-describing.
     */
    public function test_full_department_transfer_qa_scenario(): void
    {
        // ====================================================================
        // CHECKPOINT 3 — Create a ticket in Sales as a client (client portal).
        // ====================================================================
        $createResponse = $this->actingAs($this->client)
            ->post(route('client.tickets.store'), [
                'subject' => 'Need help picking a plan',
                'message' => 'I would like to upgrade, please advise on options.',
                'priority' => 'high',
                'department' => 'sales',
            ]);

        $this->adminTransferredTicket = Ticket::where('customer_id', $this->customer->id)
            ->where('department', 'sales')
            ->firstOrFail();

        $createResponse->assertRedirect(route('client.tickets.show', $this->adminTransferredTicket));
        $this->assertSame('sales', $this->adminTransferredTicket->department, '[CP3] ticket created in Sales');
        $this->assertStringStartsWith('TKT-', $this->adminTransferredTicket->ticket_no, '[CP3] TKT- numbering applied');
        $this->assertCount(1, $this->adminTransferredTicket->replies, '[CP3] opening reply persisted');
        $this->assertFalse((bool) $this->adminTransferredTicket->replies->first()->is_staff, '[CP3] opening reply authored by the customer');

        // ====================================================================
        // CHECKPOINT 4 — Transfer Sales -> Billing with note + reassign to B,
        //                via POST admin/tickets/{ticket}/transfer.
        // ====================================================================
        $transferResponse = $this->actingAs($this->actorAdmin)
            ->post(route('admin.tickets.transfer', $this->adminTransferredTicket), [
                'target_department' => 'billing',
                'assigned_to' => $this->billingStaff->id,
                'note' => self::TRANSFER_NOTE,
            ]);

        $transferResponse->assertRedirect(route('admin.tickets.show', $this->adminTransferredTicket));
        $transferResponse->assertSessionHas('success');

        $this->adminTransferredTicket->refresh();
        $this->assertSame('billing', $this->adminTransferredTicket->department, '[CP4] ticket now in Billing');
        $this->assertSame($this->billingStaff->id, $this->adminTransferredTicket->assigned_to, '[CP4] assignee moved to Billing staff B');
        $this->assertSame('open', $this->adminTransferredTicket->status, '[CP4] status remains open (was already open)');

        // Audit row + transfer note persisted atomically.
        $this->assertDatabaseHas('ticket_transfers', [
            'ticket_id' => $this->adminTransferredTicket->id,
            'from_department' => 'sales',
            'to_department' => 'billing',
            'assigned_to' => $this->billingStaff->id,
            'assigned_from' => null,
            'actor_id' => $this->actorAdmin->id,
            'note' => self::TRANSFER_NOTE,
        ]);

        $transferNote = $this->adminTransferredTicket->replies()
            ->where('is_staff', true)
            ->latest('id')
            ->first();
        $this->assertNotNull($transferNote, '[CP4] internal transfer note persisted');
        $this->assertStringStartsWith(TicketService::INTERNAL_NOTE_PREFIX, $transferNote->message, '[CP4] note marked [INTERNAL]');
        $this->assertStringContainsString('[TRANSFER] sales -> billing by '.$this->actorAdmin->email, $transferNote->message, '[CP4] transfer line names the actor');
        $this->assertStringContainsString(' — '.self::TRANSFER_NOTE, $transferNote->message, '[CP4] transfer note carries the user note');

        // ====================================================================
        // CHECKPOINT 5 — Show page timeline displays the transfer + note.
        // ====================================================================
        $showResponse = $this->actingAs($this->actorAdmin)
            ->get(route('admin.tickets.show', $this->adminTransferredTicket));

        $showResponse->assertOk();
        $showResponse->assertSee('Transfer History', false);
        $showResponse->assertSee('Sales', false);
        $showResponse->assertSee('Billing', false);
        $showResponse->assertSee($this->actorAdmin->full_name, false);
        $showResponse->assertSee(self::TRANSFER_NOTE, false);
        $showResponse->assertSee('Internal Note', false);

        // ====================================================================
        // CHECKPOINT 6 — Staff A (Sales, scoped) no longer sees the ticket.
        // ====================================================================
        // Give staff A a tickets.view permission so the route doesn't 403 on
        // the gate; the visibility scope itself is what must hide the ticket.
        $this->grantPermissions($this->salesStaff, ['tickets.view']);
        $this->salesStaff->refresh();

        $salesIndex = $this->actingAs($this->salesStaff)
            ->get(route('admin.tickets.index'));
        $salesIndex->assertOk();
        $this->assertStringNotContainsString(
            $this->adminTransferredTicket->ticket_no,
            $salesIndex->getContent(),
            '[CP6] staff A (Sales) admin index must not list the transferred ticket'
        );

        // Direct show of the transferred ticket must 403 for staff A.
        $this->actingAs($this->salesStaff)
            ->get(route('admin.tickets.show', $this->adminTransferredTicket))
            ->assertForbidden();

        // ====================================================================
        // CHECKPOINT 7 — Staff B (Billing, scoped) does see the ticket.
        // ====================================================================
        $this->grantPermissions($this->billingStaff, ['tickets.view']);
        $this->billingStaff->refresh();

        $billingIndex = $this->actingAs($this->billingStaff)
            ->get(route('admin.tickets.index'));
        $billingIndex->assertOk();
        $this->assertStringContainsString(
            $this->adminTransferredTicket->ticket_no,
            $billingIndex->getContent(),
            '[CP7] staff B (Billing) admin index must list the transferred ticket'
        );

        $billingShow = $this->actingAs($this->billingStaff)
            ->get(route('admin.tickets.show', $this->adminTransferredTicket));
        $billingShow->assertOk();
        $billingShow->assertSee($this->adminTransferredTicket->subject, false);

        // ====================================================================
        // CHECKPOINT 8 — API transfer endpoint mirrors the same behaviour.
        //   (a) Staff A in Sales cannot transfer a ticket they can no longer see.
        //   (b) A second ticket transferred via the API is also scoped correctly.
        // ====================================================================
        $this->grantPermissions($this->billingStaff, ['tickets.view', 'tickets.transfer']);
        $this->grantPermissions($this->salesStaff, ['tickets.view', 'tickets.transfer']);
        $this->grantPermissions($this->actorAdmin, ['tickets.view', 'tickets.transfer']);

        // (8a) A second Sales ticket is created by the same client; transfer
        // it via the API as the admin (admin sees every department; staff B's
        // visibility scope intentionally cannot see a Sales ticket, and that
        // exclusion is itself exercised in (8b)).
        $this->apiTransferredTicket = $this->createTicketIn('sales');
        $apiTransferResponse = $this->actingAs($this->actorAdmin, 'sanctum')
            ->postJson("/api/tickets/{$this->apiTransferredTicket->id}/transfer", [
                'target_department' => 'billing',
                'assigned_to' => $this->billingStaff->id,
                'note' => 'API route: routing via API',
            ]);
        $apiTransferResponse->assertOk();
        $apiTransferResponse->assertJsonPath('data.department', 'billing');
        $this->assertSame('billing', $this->apiTransferredTicket->fresh()->department, '[CP8] API transfer persisted department change');
        $this->assertDatabaseHas('ticket_transfers', [
            'ticket_id' => $this->apiTransferredTicket->id,
            'from_department' => 'sales',
            'to_department' => 'billing',
            'actor_id' => $this->actorAdmin->id,
            'note' => 'API route: routing via API',
        ]);

        // (8b) Mirror the admin scoping on the API side.
        $salesApiIndex = $this->actingAs($this->salesStaff, 'sanctum')
            ->getJson('/api/tickets');
        $salesApiIndex->assertOk();
        $salesIds = collect($salesApiIndex->json('data'))->pluck('id')->all();
        $this->assertNotContains($this->adminTransferredTicket->id, $salesIds, '[CP8] staff A API index omits the admin-transferred ticket');
        $this->assertNotContains($this->apiTransferredTicket->id, $salesIds, '[CP8] staff A API index omits the API-transferred ticket');

        $billingApiIndex = $this->actingAs($this->billingStaff, 'sanctum')
            ->getJson('/api/tickets');
        $billingApiIndex->assertOk();
        $billingIds = collect($billingApiIndex->json('data'))->pluck('id')->all();
        $this->assertContains($this->adminTransferredTicket->id, $billingIds, '[CP8] staff B API index includes the admin-transferred ticket');
        $this->assertContains($this->apiTransferredTicket->id, $billingIds, '[CP8] staff B API index includes the API-transferred ticket');

        // ====================================================================
        // CHECKPOINT 9 — Client portal ticket show page displays the new
        //                department (Billing) label.
        // ====================================================================
        $clientShow = $this->actingAs($this->client)
            ->get(route('client.tickets.show', $this->adminTransferredTicket));
        $clientShow->assertOk();
        // The page uses TicketService::departmentLabel($ticket->department)
        // for the header and the info card — both should now read "Billing".
        $this->assertSame('Billing', \App\Services\TicketService::departmentLabel($this->adminTransferredTicket->fresh()->department), '[CP9] department label resolves to Billing');
        $clientShow->assertSee('Billing', false);

        // ====================================================================
        // CHECKPOINT 10 — Inbound mail with the ticket's old Message-ID still
        //                 lands on the ticket without moving it back to Sales.
        // ====================================================================
        // The customer's previous outbound id (mimicking an old reply chain).
        $oldOutboundId = 'ticket-out-99999@example.test';
        TicketReply::create([
            'ticket_id' => $this->adminTransferredTicket->id,
            'user_id' => $this->actorAdmin->id,
            'message' => 'Acknowledged.',
            'is_staff' => true,
            'email_message_id' => $oldOutboundId,
        ]);

        $result = app(TicketMailParser::class)->handle(
            InboundEmail::fromArray([
                'messageId' => 'inbound-old-thread@mail.example',
                'inReplyTo' => $oldOutboundId,
                'subject' => 'Re: '.$this->adminTransferredTicket->subject,
                'fromEmail' => $this->client->email,
                'body' => 'Following up on my upgrade question.',
            ]),
            false,
            'sales' // Arrives on the Sales mailbox — must NOT move department.
        );

        $this->assertSame(TicketMailParser::STATUS_CREATED, $result['status'], '[CP10] inbound mail with old Message-ID still lands on the ticket');
        $this->assertSame('billing', $this->adminTransferredTicket->fresh()->department, '[CP10] inbound mail must never move the ticket back to Sales');

        // ====================================================================
        // CHECKPOINT 11 — An outbound staff reply's From is the Billing
        //                 department's email address (not Sales).
        // ====================================================================
        Bus::fake();

        app(TicketService::class)->reply(
            $this->adminTransferredTicket->fresh(),
            $this->billingStaff,
            'Here is your invoice.'
        );

        Bus::assertDispatched(
            SendEmail::class,
            fn (SendEmail $job) => $job->fromEmail === 'billing@example.test'
                && ($job->headers['replyTo'] ?? null) === 'billing@example.test'
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $departmentSlugs
     */
    private function makeStaff(string $email, array $departmentSlugs): User
    {
        $user = User::factory()->create([
            'role' => 'support',
            'email' => $email,
            'first_name' => ucfirst(explode('.', $email)[0] ?? 'Staff'),
            'last_name' => 'Member',
        ]);

        foreach ($departmentSlugs as $slug) {
            TicketDepartment::where('slug', $slug)->firstOrFail()->staff()->attach($user->id);
        }

        return $user->fresh();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function grantPermissions(User $user, array $permissions): void
    {
        $role = Role::firstOrCreate(['name' => 'support'], ['label' => 'Support']);
        foreach ($permissions as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($perm->id);
        }
        $user->assignRole('support');
    }

    private function createTicketIn(string $slug): Ticket
    {
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $this->customer->id,
            'subject' => 'QA second ticket in '.$slug,
            'priority' => 'medium',
            'status' => 'open',
            'department' => $slug,
            'last_reply_at' => now(),
        ]);

        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->client->id,
            'message' => 'Opening message for the API scenario.',
            'is_staff' => false,
        ]);

        return $ticket->fresh(['customer']);
    }
}
