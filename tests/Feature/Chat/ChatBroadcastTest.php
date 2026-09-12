<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Events\Chat\ChatMessageDeleted;
use App\Events\Chat\ChatMessageEdited;
use App\Events\Chat\NewChatMessage;
use App\Events\Chat\TypingIndicator;
use App\Events\Chat\UserPresence;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatBodyHtml;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The chat's broadcast layer: what is published, on which channel, and — the
 * part that actually decides whether the chat feels real-time — that none of it
 * is ever queued.
 */
class ChatBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The regression that matters most.
     *
     * This app runs QUEUE_CONNECTION=database with a single scheduled worker
     * (`queue:work --queue=emails,default --stop-when-empty`) that fires once a
     * minute. An event marked ShouldBroadcast instead of ShouldBroadcastNow
     * would be queued, sit in the `jobs` table for up to ~60 seconds and be
     * delivered long after the conversation moved on — with nothing failing and
     * no error anywhere to explain it.
     */
    public function test_every_chat_event_broadcasts_synchronously(): void
    {
        $events = $this->chatEventClasses();

        $this->assertNotEmpty($events, 'No chat events were discovered — the reflection guard is not guarding anything.');

        foreach ($events as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->implementsInterface(ShouldBroadcastNow::class),
                "{$class} must implement ShouldBroadcastNow.",
            );

            $this->assertFalse(
                $reflection->implementsInterface(ShouldQueue::class),
                "{$class} implements ShouldQueue — it would be queued and delivered up to a minute late.",
            );

            // ShouldBroadcastNow extends ShouldBroadcast, so the meaningful
            // check is that nothing implements ShouldBroadcast *without* Now.
            $this->assertTrue(
                ! $reflection->implementsInterface(ShouldBroadcast::class)
                    || $reflection->implementsInterface(ShouldBroadcastNow::class),
                "{$class} is a plain ShouldBroadcast — use ShouldBroadcastNow.",
            );
        }
    }

    public function test_sending_a_message_leaves_the_jobs_table_empty(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->create();
        app(ChatService::class)->addMember($conversation, $user);

        $this->assertSame(0, DB::table('jobs')->count());

        app(ChatService::class)->sendMessage($conversation, $user, 'Anyone about?');

        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'A row in `jobs` means the broadcast was queued — the scheduled worker runs once a minute.',
        );
    }

    public function test_new_message_event_targets_the_private_conversation_channel(): void
    {
        $message = ChatConversationMessage::factory()->create();
        $event = new NewChatMessage($message);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-chat.conversation.'.$message->conversation_id, (string) $channels[0]);
        $this->assertSame('chat.message.new', $event->broadcastAs());
    }

    public function test_the_broadcast_payload_is_ids_and_rendered_text_only(): void
    {
        $user = User::factory()->create();
        $message = ChatConversationMessage::factory()->create([
            'user_id' => $user->id,
            'body' => 'ship it',
        ]);
        $message->load('user');

        $payload = (new NewChatMessage($message))->broadcastWith()['message'];

        $this->assertSame($message->id, $payload['id']);
        $this->assertSame($message->conversation_id, $payload['conversation_id']);
        $this->assertSame('ship it', $payload['body']);
        $this->assertSame($user->id, $payload['user']['id']);
        $this->assertFalse($payload['is_deleted']);
        $this->assertFalse($payload['is_edited']);

        // No object graphs on the wire — the send happens inside the request.
        $this->assertSame([], $payload['attachments']);
        $this->assertSame([], $payload['entity_links']);
    }

    public function test_a_message_body_is_escaped_before_it_is_broadcast(): void
    {
        $message = ChatConversationMessage::factory()->create([
            'body' => '<script>alert(1)</script> **bold**',
        ]);

        $payload = (new NewChatMessage($message))->broadcastWith()['message'];

        $this->assertStringNotContainsString('<script>', $payload['body_html']);
        $this->assertStringContainsString('&lt;script&gt;', $payload['body_html']);
        $this->assertStringContainsString('<strong>bold</strong>', $payload['body_html']);
    }

    public function test_a_deleted_message_never_broadcasts_its_text(): void
    {
        $message = ChatConversationMessage::factory()->create(['body' => 'sensitive']);
        $message->delete();
        $message = ChatConversationMessage::withTrashed()->find($message->id);

        $payload = (new NewChatMessage($message))->broadcastWith()['message'];

        $this->assertTrue($payload['is_deleted']);
        $this->assertSame('[deleted]', $payload['body']);
        $this->assertStringNotContainsString('sensitive', json_encode($payload));

        // The delete event itself carries ids only.
        $deleted = new ChatMessageDeleted($message->id, $message->conversation_id, null);
        $this->assertSame(
            ['id', 'conversation_id', 'parent_id'],
            array_keys($deleted->broadcastWith()),
        );
    }

    public function test_typing_and_presence_use_presence_channels_and_carry_no_message_text(): void
    {
        $typing = new TypingIndicator(7, 3, 'Alice', true);

        $this->assertInstanceOf(PresenceChannel::class, $typing->broadcastOn()[0]);
        $this->assertSame('presence-chat.typing.7', (string) $typing->broadcastOn()[0]);
        $this->assertSame(
            ['conversation_id', 'user_id', 'user_name', 'typing'],
            array_keys($typing->broadcastWith()),
        );

        $presence = new UserPresence(3, 'Alice');

        $this->assertSame('presence-chat.presence', (string) $presence->broadcastOn()[0]);
        $this->assertSame(UserPresence::ONLINE, $presence->broadcastWith()['status']);
    }

    public function test_the_service_dispatches_on_send_edit_and_delete(): void
    {
        Event::fake([NewChatMessage::class, ChatMessageEdited::class, ChatMessageDeleted::class]);

        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->create();
        $chat = app(ChatService::class);
        $chat->addMember($conversation, $user);

        $message = $chat->sendMessage($conversation, $user, 'Hello');
        Event::assertDispatched(NewChatMessage::class, fn ($e) => $e->message->id === $message->id);

        $chat->editMessage($message, 'Hello again');
        Event::assertDispatched(ChatMessageEdited::class);

        $chat->deleteMessage($message);
        Event::assertDispatched(ChatMessageDeleted::class, fn ($e) => $e->messageId === $message->id);
    }

    public function test_code_fences_survive_as_literal_text(): void
    {
        $html = ChatBodyHtml::render("try this:\n```\n<b>**not bold**</b>\n```");

        $this->assertStringContainsString('<pre><code>', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    /**
     * @return array<int, class-string>
     */
    private function chatEventClasses(): array
    {
        $directory = app_path('Events/Chat');

        if (! is_dir($directory)) {
            return [];
        }

        return collect(iterator_to_array(Finder::create()->files()->in($directory)->name('*.php')))
            ->map(static fn (SplFileInfo $file) => 'App\\Events\\Chat\\'.$file->getBasename('.php'))
            ->filter(static fn (string $class) => class_exists($class))
            ->values()
            ->all();
    }
}
