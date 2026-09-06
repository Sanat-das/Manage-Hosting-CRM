<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientPortalAttachmentAccumulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_reply_stores_multiple_files_in_one_post(): void
    {
        Storage::fake('local');

        [$user, $ticket] = $this->clientWithTicket();

        $response = $this->actingAs($user)->post(
            route('client.tickets.reply', $ticket->id),
            [
                'message' => 'Here are two files.',
                'attachments' => [
                    UploadedFile::fake()->create('alpha.pdf', 50, 'application/pdf'),
                    UploadedFile::fake()->create('beta.png', 80, 'image/png'),
                ],
            ]
        );

        $response->assertRedirect(route('client.tickets.show', $ticket->id));

        $reply = $ticket->fresh()->replies()->latest()->first();
        $this->assertNotNull($reply, 'Reply was not saved.');

        $attachments = $reply->attachments()->orderBy('id')->get();
        $this->assertCount(2, $attachments, 'Expected 2 attachments, got '.$attachments->count());
        $this->assertSame('alpha.pdf', $attachments[0]->filename);
        $this->assertSame('beta.png',  $attachments[1]->filename);
        $this->assertSame('application/pdf', $attachments[0]->mime_type);
        $this->assertSame('image/png',       $attachments[1]->mime_type);

        foreach ($attachments as $att) {
            Storage::disk('local')->assertExists($att->path);
        }
    }

    public function test_client_reply_stores_zero_files_when_none_sent(): void
    {
        Storage::fake('local');

        [$user, $ticket] = $this->clientWithTicket();

        $response = $this->actingAs($user)->post(
            route('client.tickets.reply', $ticket->id),
            ['message' => 'No files here.']
        );

        $response->assertRedirect(route('client.tickets.show', $ticket->id));

        $reply = $ticket->fresh()->replies()->latest()->first();
        $this->assertNotNull($reply);
        $this->assertCount(0, $reply->attachments);
    }

    public function test_client_cannot_reply_on_another_customers_ticket(): void
    {
        Storage::fake('local');

        $otherUser = User::factory()->create(['role' => 'client']);
        Customer::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $otherTicket = Ticket::create([
            'ticket_no'    => 'TKT-99999',
            'customer_id'  => Customer::where('user_id', $otherUser->id)->first()->id,
            'subject'      => 'Other ticket',
            'priority'     => 'low',
            'status'       => 'open',
            'department'   => 'support',
            'last_reply_at' => now(),
        ]);

        [$myUser] = $this->clientWithTicket();

        $response = $this->actingAs($myUser)->post(
            route('client.tickets.reply', $otherTicket->id),
            ['message' => 'Trying to hijack.']
        );

        $response->assertStatus(404);
    }

    /** @return array{0: User, 1: Ticket} */
    private function clientWithTicket(): array
    {
        $user     = User::factory()->create(['role' => 'client']);
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);
        $ticket   = Ticket::create([
            'ticket_no'    => 'TKT-'.str_pad((string) random_int(1, 99998), 5, '0', STR_PAD_LEFT),
            'customer_id'  => $customer->id,
            'subject'      => 'Test ticket',
            'priority'     => 'medium',
            'status'       => 'open',
            'department'   => 'support',
            'last_reply_at' => now(),
        ]);

        return [$user, $ticket];
    }
}
