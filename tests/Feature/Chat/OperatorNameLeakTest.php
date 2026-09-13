<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatMessagePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesChatUsers;
use Tests\TestCase;

/**
 * Privacy leak: staff real name must not reach unauthenticated visitors.
 *
 * Failing-first proof: guest fetching its own transcript receives NO staff
 * real name, NO staff email, and no email-derived avatar hash. Staff payload
 * still CONTAINS the real name.
 */
class OperatorNameLeakTest extends TestCase
{
    use CreatesChatUsers;
    use RefreshDatabase;

    private ChatService $chat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chat = app(ChatService::class);
    }

    public function test_guest_transcript_does_not_leak_operator_real_name(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $uniqueName = 'QA Verify OP '.uniqid();
        $operator->first_name = $uniqueName;
        $operator->last_name = 'LeakTest';
        $operator->save();
        $operator->refresh();
        $expectedStaffFullName = $operator->full_name; // e.g. "QA Verify OP xxx LeakTest"

        // Guest starts chat
        $start = $this->postJson(route('chat.start'), [
            'name' => 'Guesty',
            'email' => 'guesty@example.com',
            'body' => 'Hello',
        ])->assertCreated();
        $conversationId = $start->json('conversation_id');
        $token = $start->json('token');
        $conversation = ChatConversation::findOrFail($conversationId);
        $this->chat->assignOperator($conversation, $operator);

        // Operator replies
        $this->chat->sendMessage($conversation, $operator, 'How can I help?');

        // Guest fetches transcript
        $raw = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $conversationId))
            ->assertOk()
            ->getContent();

        // Fail if staff real name appears anywhere in JSON
        $this->assertStringNotContainsString($expectedStaffFullName, $raw, 'Guest transcript must NOT contain operator real name');
        $this->assertStringNotContainsString($operator->email, $raw, 'Guest transcript must NOT contain operator email');
        // gravatar hash derived from email
        $hash = md5(strtolower(trim($operator->email)));
        $this->assertStringNotContainsString($hash, $raw, 'Guest transcript must NOT contain email-derived gravatar hash');

        $payload = json_decode($raw, true);
        $messages = $payload['messages'];
        $operatorMessages = array_filter($messages, fn ($m) => $m['is_operator'] ?? false);
        $this->assertNotEmpty($operatorMessages, 'Expected operator messages in transcript');
        foreach ($operatorMessages as $msg) {
            $this->assertSame('Support', $msg['author_name'], 'Operator author_name must be generic Support for guest');
            $this->assertArrayNotHasKey('user', $msg, 'Guest payload must not expose user object');
            $this->assertArrayNotHasKey('author_email', $msg);
            $this->assertArrayNotHasKey('avatar', $msg);
            $this->assertArrayNotHasKey('gravatar', $msg);
        }

        // Prove payload still renders correctly with author attribution
        $this->assertCount(2, $messages);
    }

    public function test_staff_payload_still_contains_real_operator_name(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $uniqueName = 'Staff Real '.uniqid();
        $operator->first_name = $uniqueName;
        $operator->last_name = 'Name';
        $operator->save();
        $operator->refresh();
        $realName = $operator->full_name;

        $conversation = $this->chat->startCustomerChat(
            ['name' => 'Guest', 'email' => 'guest2@example.com'],
            null,
            'Hi',
        );
        $this->chat->assignOperator($conversation, $operator);
        $msg = $this->chat->sendMessage($conversation, $operator, 'Staff reply');
        $msg->load(['user', 'attachments', 'entityLinks']);

        $staffPayload = ChatMessagePayload::for($msg);
        $this->assertSame($realName, $staffPayload['author_name'], 'Staff payload must still contain real name');
        $this->assertNotNull($staffPayload['user']);
        $this->assertSame($realName, $staffPayload['user']['name']);
        $this->assertStringContainsString($realName, json_encode($staffPayload));
    }

    public function test_guest_sees_own_name_on_own_messages(): void
    {
        $start = $this->postJson(route('chat.start'), [
            'name' => 'My Guest Name',
            'email' => 'myguest@example.com',
            'body' => 'Hello from me',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');

        $raw = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk()
            ->getContent();

        $payload = json_decode($raw, true);
        $msg = $payload['messages'][0];
        $this->assertTrue($msg['is_guest']);
        $this->assertFalse($msg['is_operator']);
        $this->assertSame('My Guest Name', $msg['author_name'], 'Guest own name is theirs to see');
        $this->assertStringContainsString('My Guest Name', $raw);
    }

    public function test_deleted_operator_message_shows_tombstone_without_real_name(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $operator->first_name = 'DeleteMe '.uniqid();
        $operator->last_name = 'Tester';
        $operator->save();
        $operator->refresh();
        $realName = $operator->full_name;

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Guest', 'email' => 'guest3@example.com', 'body' => 'Hi',
        ])->assertCreated();
        $id = $start->json('conversation_id');
        $token = $start->json('token');
        $conversation = ChatConversation::findOrFail($id);
        $this->chat->assignOperator($conversation, $operator);
        $msg = $this->chat->sendMessage($conversation, $operator, 'To be deleted');
        $this->chat->deleteMessage($msg);

        $raw = $this->withHeader('X-Chat-Token', $token)
            ->getJson(route('chat.messages', $id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($realName, $raw, 'Deleted tombstone must not leak operator name');
        $this->assertStringNotContainsString($operator->email, $raw);
        $payload = json_decode($raw, true);
        $found = collect($payload['messages'])->firstWhere('id', $msg->id);
        $this->assertNotNull($found);
        $this->assertTrue($found['is_deleted']);
        $this->assertSame('[deleted]', $found['body']);
        $this->assertSame('Support', $found['author_name'], 'Deleted operator tombstone author_name must be Support for guest');
    }

    public function test_guest_payload_has_no_email_derived_avatar_hash_in_any_field(): void
    {
        $operator = $this->chatUser('chat.view', 'chat.manage');
        $operator->email = 'leakcheck+'.uniqid().'@example.com';
        $operator->first_name = 'Avatar';
        $operator->last_name = 'Leak '.uniqid();
        $operator->save();
        $operator->refresh();

        $start = $this->postJson(route('chat.start'), [
            'name' => 'Guest', 'email' => 'g@example.com', 'body' => 'Hello',
        ])->assertCreated();
        $conversation = ChatConversation::findOrFail($start->json('conversation_id'));
        $this->chat->assignOperator($conversation, $operator);
        $this->chat->sendMessage($conversation, $operator, 'hi');

        $raw = $this->withHeader('X-Chat-Token', $start->json('token'))
            ->getJson(route('chat.messages', $conversation->id))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($operator->email, $raw);
        $this->assertStringNotContainsString(md5(strtolower(trim($operator->email))), $raw);
        // Ensure no avatar/gravatar field exists at all in guest payload
        $payload = json_decode($raw, true);
        foreach ($payload['messages'] as $msg) {
            foreach (['author_email', 'email', 'avatar', 'avatar_url', 'gravatar', 'gravatar_url', 'gravatar_hash'] as $field) {
                $this->assertArrayNotHasKey($field, $msg, "Guest payload must not contain {$field}");
            }
        }
    }
}
