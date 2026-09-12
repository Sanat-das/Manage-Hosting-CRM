<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Models\MessageEntityLink;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The new Slack-like tables and their models: relations, soft deletes, the
 * uniqueness guarantees the toggle logic depends on, and the mass-assignment
 * guard on guest_token.
 */
class ChatModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_holds_participants_and_threaded_messages(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $channel = ChatConversation::factory()->create(['created_by' => $alice->id]);

        ChatParticipant::factory()->create(['conversation_id' => $channel->id, 'user_id' => $alice->id]);
        ChatParticipant::factory()->create(['conversation_id' => $channel->id, 'user_id' => $bob->id]);

        $root = ChatConversationMessage::factory()->create([
            'conversation_id' => $channel->id,
            'user_id' => $alice->id,
            'body' => 'Deploy is green.',
        ]);

        $reply = ChatConversationMessage::factory()
            ->replyTo($root)
            ->create(['user_id' => $bob->id, 'body' => 'Nice.']);

        $channel->refresh()->load(['participants', 'messages']);

        $this->assertCount(2, $channel->participants);
        $this->assertCount(2, $channel->messages);
        $this->assertSame($root->id, $reply->parent_id);
        $this->assertTrue($reply->isThreadReply());
        $this->assertFalse($root->isThreadReply());
        $this->assertTrue($root->replies->contains($reply));
        $this->assertSame($alice->id, $channel->creator->id);
    }

    public function test_soft_deleted_message_keeps_the_thread_and_hides_its_body(): void
    {
        $root = ChatConversationMessage::factory()->create(['body' => 'secret']);
        $reply = ChatConversationMessage::factory()->replyTo($root)->create();

        $root->delete();

        $this->assertSoftDeleted('chat_conversation_messages', ['id' => $root->id]);
        $this->assertTrue($root->fresh()->isDeleted());
        $this->assertSame('[deleted]', $root->fresh()->visibleBody());

        // The reply survives: the thread is not orphaned.
        $this->assertNotNull($reply->fresh());
        $this->assertDatabaseHas('chat_conversation_messages', ['id' => $reply->id, 'parent_id' => $root->id]);
    }

    public function test_reaction_is_unique_per_message_user_and_emoji(): void
    {
        $message = ChatConversationMessage::factory()->create();
        $user = User::factory()->create();

        ChatReaction::factory()->create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => '👍',
        ]);

        $this->expectException(QueryException::class);

        ChatReaction::factory()->create([
            'message_id' => $message->id,
            'user_id' => $user->id,
            'emoji' => '👍',
        ]);
    }

    public function test_participant_is_unique_per_conversation_and_user(): void
    {
        $conversation = ChatConversation::factory()->create();
        $user = User::factory()->create();

        ChatParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $user->id]);

        $this->expectException(QueryException::class);

        ChatParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $user->id]);
    }

    public function test_many_guests_may_share_one_conversation_despite_the_unique_index(): void
    {
        $inbox = ChatConversation::factory()->customerInbox()->create();

        // Both rows have a null user_id. MySQL and SQLite both treat NULLs as
        // distinct in a unique index, which is what makes guest rows possible.
        foreach (['tok-a', 'tok-b'] as $token) {
            $participant = new ChatParticipant(['conversation_id' => $inbox->id, 'role' => ChatParticipant::ROLE_MEMBER]);
            $participant->guest_token = $token;
            $participant->save();
        }

        $this->assertSame(2, $inbox->participants()->count());
        $this->assertTrue($inbox->participants()->first()->isGuest());
    }

    public function test_guest_token_is_not_mass_assignable(): void
    {
        $conversation = ChatConversation::create([
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
            'guest_token' => 'injected-from-request',
        ]);

        $this->assertNull($conversation->fresh()->guest_token);

        $message = ChatConversationMessage::create([
            'conversation_id' => $conversation->id,
            'body' => 'hi',
            'guest_token' => 'injected-from-request',
        ]);

        $this->assertNull($message->fresh()->guest_token);
    }

    public function test_attachments_and_entity_links_hang_off_a_message(): void
    {
        $message = ChatConversationMessage::factory()->create();
        $ticket = Ticket::create([
            'ticket_no' => 'TKT-CHATTEST',
            'subject' => 'Linked from chat',
            'priority' => 'medium',
            'status' => 'open',
            'department' => 'support',
        ]);

        ChatMessageAttachment::factory()->create(['message_id' => $message->id]);
        MessageEntityLink::factory()->for_($ticket)->create(['message_id' => $message->id]);

        $message->load(['attachments', 'entityLinks.linkable']);

        $this->assertCount(1, $message->attachments);
        $this->assertTrue($message->attachments->first()->isImage());

        $link = $message->entityLinks->first();
        $this->assertSame('ticket', $link->typeKey());
        $this->assertTrue($ticket->is($link->linkable));
        $this->assertSame(Ticket::class, MessageEntityLink::classFor('ticket'));
        $this->assertNull(MessageEntityLink::classFor('server'));
    }

    public function test_eager_loading_a_conversation_does_not_n_plus_one(): void
    {
        $conversation = ChatConversation::factory()->create();
        ChatParticipant::factory()->count(3)->create(['conversation_id' => $conversation->id]);
        ChatConversationMessage::factory()->count(5)->create(['conversation_id' => $conversation->id]);

        DB::enableQueryLog();

        $loaded = ChatConversation::with(['participants', 'messages'])->find($conversation->id);
        $loaded->participants->each(fn ($p) => $p->role);
        $loaded->messages->each(fn ($m) => $m->body);

        // One for the conversation, one per eager-loaded relation.
        $this->assertCount(3, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function test_entity_link_row_has_no_updated_at_column_to_write(): void
    {
        $link = MessageEntityLink::factory()->create();

        $this->assertNotNull($link->created_at);
        $this->assertNull(MessageEntityLink::UPDATED_AT);
    }
}
