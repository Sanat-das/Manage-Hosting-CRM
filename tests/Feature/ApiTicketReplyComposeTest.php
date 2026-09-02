<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Outlook-style rework Task 9: API parity for the reply compose fields
 * (to/cc/bcc/html_body/attachments) added to the web forms in Task 5.
 */
class ApiTicketReplyComposeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plain_reply_with_just_message_still_works_unchanged(): void
    {
        Queue::fake();

        [$ticket] = $this->ticketWithCustomer('client@example.test');
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/reply", [
                'message' => 'We have fixed it.',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.message', 'We have fixed it.');
        $response->assertJsonPath('data.has_html_body', false);
        $response->assertJsonPath('data.to', null);
        $response->assertJsonPath('data.attachments', []);
    }

    public function test_a_reply_with_to_cc_bcc_html_body_and_an_attachment_round_trips(): void
    {
        Queue::fake();
        Storage::fake('local');

        [$ticket] = $this->ticketWithCustomer('client@example.test');
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/reply", [
                'message' => 'We have fixed it.',
                'html_body' => '<p>We have fixed it.</p>',
                'to' => ['personal-inbox@example.test'],
                'cc' => ['manager@example.test'],
                'bcc' => ['audit@example.test'],
                'attachments' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.has_html_body', true);
        $response->assertJsonPath('data.to', ['personal-inbox@example.test']);
        $response->assertJsonPath('data.cc', ['manager@example.test']);
        $response->assertJsonPath('data.bcc', ['audit@example.test']);
        $response->assertJsonCount(1, 'data.attachments');
        $response->assertJsonPath('data.attachments.0.filename', 'report.pdf');
    }

    public function test_an_invalid_address_in_cc_is_rejected_with_a_422(): void
    {
        [$ticket] = $this->ticketWithCustomer('client@example.test');
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/tickets/{$ticket->id}/reply", [
                'message' => 'We have fixed it.',
                'cc' => ['not-an-email'],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('cc.0');
    }

    public function test_show_includes_the_new_reply_fields_for_a_plain_reply(): void
    {
        [$ticket, $customer] = $this->ticketWithCustomer('client@example.test');
        $token = $this->staffToken();

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/tickets/{$ticket->id}");

        $response->assertOk();
        $reply = collect($response->json('data.replies'))->first();
        $this->assertArrayHasKey('has_html_body', $reply);
        $this->assertArrayHasKey('to', $reply);
        $this->assertArrayHasKey('cc', $reply);
        $this->assertArrayHasKey('bcc', $reply);
        $this->assertArrayHasKey('attachments', $reply);
    }

    /**
     * @return array{0: Ticket, 1: Customer}
     */
    private function ticketWithCustomer(string $email): array
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'client']);
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

        \App\Models\TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->user_id,
            'message' => 'It has been down since noon.',
            'is_staff' => false,
        ]);

        return [$ticket, $customer];
    }

    private function staffToken(): string
    {
        $user = User::factory()->create(['role' => 'admin']);

        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin']);
        $permission = Permission::firstOrCreate(['name' => 'tickets.view'], ['label' => 'tickets.view']);
        $role->permissions()->syncWithoutDetaching($permission->id);
        $user->assignRole('admin');

        return $user->createToken('test-token')->plainTextToken;
    }
}
