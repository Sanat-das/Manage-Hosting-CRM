<?php

namespace Tests\Unit;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Outlook-style rework Task 2: the to/cc/bcc array casts and the
 * ticket_reply -> ticket_attachments relation.
 */
class TicketReplyAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_cc_bcc_are_cast_to_arrays(): void
    {
        $reply = $this->makeReply();

        $reply->update([
            'to' => ['customer@example.test'],
            'cc' => ['manager@example.test', 'sales@example.test'],
            'bcc' => ['audit@example.test'],
        ]);

        $fresh = $reply->fresh();

        $this->assertSame(['customer@example.test'], $fresh->to);
        $this->assertSame(['manager@example.test', 'sales@example.test'], $fresh->cc);
        $this->assertSame(['audit@example.test'], $fresh->bcc);
    }

    public function test_to_cc_bcc_default_to_null_when_unset(): void
    {
        $reply = $this->makeReply();

        $this->assertNull($reply->fresh()->to);
        $this->assertNull($reply->fresh()->cc);
        $this->assertNull($reply->fresh()->bcc);
    }

    public function test_attachments_relation_returns_files_on_the_reply(): void
    {
        $reply = $this->makeReply();

        $attachment = TicketAttachment::create([
            'ticket_reply_id' => $reply->id,
            'disk' => 'local',
            'path' => 'ticket-attachments/1/1/screenshot.png',
            'filename' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size_bytes' => 12345,
            'is_inline' => true,
            'content_id' => 'inline-image-1',
        ]);

        $this->assertTrue($reply->attachments->contains($attachment));
        $this->assertTrue($attachment->is_inline);
        $this->assertSame(12345, $attachment->size_bytes);
        $this->assertSame($reply->id, $attachment->reply->id);
    }

    private function makeReply(): TicketReply
    {
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'guest_email' => 'guest@example.test',
            'guest_name' => 'Guest',
            'subject' => 'Test ticket',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
            'last_reply_at' => now(),
        ]);

        return TicketReply::create([
            'ticket_id' => $ticket->id,
            'message' => 'Hello',
            'is_staff' => false,
        ]);
    }
}
