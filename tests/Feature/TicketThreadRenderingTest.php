<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Outlook-style rework Task 7: To/Cc/Bcc chips, sanitized HTML rendering, and
 * attachment downloads in the admin + client thread views.
 */
class TicketThreadRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_view_renders_sanitized_html_and_strips_a_script_tag(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $this->replyOn($ticket, $customer->user_id, true, [
            'html_body' => '<p>Hello</p><script>alert(1)</script>',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('Hello');
        // Not assertDontSee('<script') — the page legitimately has script tags
        // (the Task 6 editor, asset bundles). The actual payload is the proof.
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('alert(1)');
    }

    public function test_admin_view_renders_to_cc_bcc_chips_only_when_present(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $this->replyOn($ticket, $customer->user_id, true, [
            'to' => ['client@example.test'],
            'cc' => ['manager@example.test'],
            'bcc' => ['audit@example.test'],
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertSee('manager@example.test');
        $response->assertSee('audit@example.test');
    }

    public function test_admin_view_shows_no_recipient_chips_for_a_plain_reply(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $this->replyOn($ticket, $customer->user_id, true);

        $response = $this->actingAsAdmin()->get(route('admin.tickets.show', $ticket));

        $response->assertOk();
        $response->assertDontSee('audit@example.test');
    }

    public function test_client_view_never_renders_bcc_even_when_the_reply_has_one(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $this->replyOn($ticket, $customer->user_id, true, [
            'to' => ['client@example.test'],
            'cc' => ['manager@example.test'],
            'bcc' => ['audit@example.test'],
        ]);

        $response = $this->actingAs($customer->user)->get(route('client.tickets.show', $ticket->id));

        $response->assertOk();
        $response->assertSee('manager@example.test');
        $response->assertDontSee('audit@example.test');
    }

    public function test_client_view_renders_sanitized_html(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $this->replyOn($ticket, $customer->user_id, true, [
            'html_body' => '<p>Fixed it</p><img src="x" onerror="alert(1)">',
        ]);

        $response = $this->actingAs($customer->user)->get(route('client.tickets.show', $ticket->id));

        $response->assertOk();
        $response->assertSee('Fixed it');
        $response->assertDontSee('onerror', false);
        $response->assertDontSee('alert(1)');
    }

    public function test_admin_can_download_an_attachment_on_a_visible_ticket(): void
    {
        Storage::fake('local');

        [$ticket, $customer] = $this->ticketWithCustomer();
        $reply = $this->replyOn($ticket, $customer->user_id, true);
        Storage::disk('local')->put('ticket-attachments/x/y/report.pdf', 'contents');
        $attachment = TicketAttachment::create([
            'ticket_reply_id' => $reply->id,
            'disk' => 'local',
            'path' => 'ticket-attachments/x/y/report.pdf',
            'filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'is_inline' => false,
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.attachments.show', [$ticket, $attachment]));

        $response->assertOk();
    }

    public function test_admin_cannot_download_an_attachment_belonging_to_a_different_ticket(): void
    {
        Storage::fake('local');

        [$ticketA, $customerA] = $this->ticketWithCustomer();
        [$ticketB, $customerB] = $this->ticketWithCustomer();
        $replyB = $this->replyOn($ticketB, $customerB->user_id, true);

        Storage::disk('local')->put('ticket-attachments/b/1/secret.pdf', 'contents');
        $attachment = TicketAttachment::create([
            'ticket_reply_id' => $replyB->id,
            'disk' => 'local',
            'path' => 'ticket-attachments/b/1/secret.pdf',
            'filename' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'is_inline' => false,
        ]);

        // Requesting ticketB's attachment through ticketA's URL must 404, not
        // silently serve the file — this is the guard against a staff member
        // enumerating ticket ids in the attachment URL.
        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.attachments.show', [$ticketA, $attachment]));

        $response->assertNotFound();
    }

    public function test_a_customer_cannot_download_another_customers_attachment(): void
    {
        Storage::fake('local');

        [$ticketA, $customerA] = $this->ticketWithCustomer();
        [$ticketB, $customerB] = $this->ticketWithCustomer();
        $replyB = $this->replyOn($ticketB, $customerB->user_id, false);

        Storage::disk('local')->put('ticket-attachments/b/1/secret.pdf', 'contents');
        $attachment = TicketAttachment::create([
            'ticket_reply_id' => $replyB->id,
            'disk' => 'local',
            'path' => 'ticket-attachments/b/1/secret.pdf',
            'filename' => 'secret.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'is_inline' => false,
        ]);

        // customerA tries to fetch customerB's attachment via customerA's own
        // (unrelated) ticket id.
        $response = $this->actingAs($customerA->user)
            ->get(route('client.tickets.attachments.show', [$ticketA->id, $attachment]));

        $response->assertNotFound();
    }

    public function test_admin_can_view_the_raw_original_for_an_inbound_reply(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $reply = $this->replyOn($ticket, $customer->user_id, false, [
            'raw_source' => "From: client@example.test\r\nSubject: hi\r\n\r\nOriginal body.",
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.replies.original', [$ticket, $reply]));

        $response->assertOk();
        $response->assertSee('Original body.');
        $response->assertDontSee('reconstructed from stored ticket data', false);
    }

    public function test_admin_view_shows_a_reconstructed_notice_for_a_staff_reply_with_no_raw_source(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $reply = $this->replyOn($ticket, $customer->user_id, true);

        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.replies.original', [$ticket, $reply]));

        $response->assertOk();
        $response->assertSee('reconstructed from stored ticket data', false);
    }

    public function test_show_original_404s_for_a_portal_reply_with_nothing_to_show(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer();
        $reply = $this->replyOn($ticket, $customer->user_id, false);

        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.replies.original', [$ticket, $reply]));

        $response->assertNotFound();
    }

    public function test_show_original_404s_when_the_reply_belongs_to_a_different_ticket(): void
    {
        [$ticketA, $customerA] = $this->ticketWithCustomer();
        [$ticketB, $customerB] = $this->ticketWithCustomer();
        $replyB = $this->replyOn($ticketB, $customerB->user_id, false, [
            'raw_source' => 'From: b@example.test',
        ]);

        $response = $this->actingAsAdmin()
            ->get(route('admin.tickets.replies.original', [$ticketA, $replyB]));

        $response->assertNotFound();
    }

    /**
     * @return array{0: Ticket, 1: Customer}
     */
    private function ticketWithCustomer(): array
    {
        $user = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'subject' => 'Cannot reach my site',
            'priority' => 'high',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        return [$ticket, $customer];
    }

    private function replyOn(Ticket $ticket, ?int $userId, bool $isStaff, array $attributes = []): TicketReply
    {
        return TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'message' => 'Hello there.',
            'is_staff' => $isStaff,
            ...$attributes,
        ]);
    }

    private function actingAsAdmin(): self
    {
        $user = User::factory()->create(['role' => 'admin']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        $permission = Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'tickets.view']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->assignRole('admin');

        return $this->actingAs($user);
    }
}
