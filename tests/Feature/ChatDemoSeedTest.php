<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\Demo\DummyDataConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 18 failing-first proof: new chat minima and idempotency.
 */
final class ChatDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_demo_minima(): void
    {
        $this->seed();

        $conversations = DB::table('chat_conversations')->count();
        $messages = DB::table('chat_conversation_messages')->count();
        $participants = DB::table('chat_participants')->count();
        $reactions = DB::table('chat_reactions')->count();
        $links = DB::table('message_entity_links')->count();

        $this->assertGreaterThanOrEqual(3, $conversations, "chat_conversations {$conversations} < 3");
        $this->assertGreaterThanOrEqual(20, $messages, "chat_conversation_messages {$messages} < 20");
        $this->assertGreaterThanOrEqual(8, $participants, "chat_participants {$participants} < 8");
        $this->assertGreaterThanOrEqual(1, $reactions, "chat_reactions {$reactions} < 1");
        $this->assertGreaterThanOrEqual(1, $links, "message_entity_links {$links} < 1");

        // DummyDataConfig minima must be updated
        $this->assertGreaterThanOrEqual(8, DummyDataConfig::minRows('chat_conversations'));
        $this->assertGreaterThanOrEqual(20, DummyDataConfig::minRows('chat_conversation_messages'));

        // Channels: general, support, billing private
        $this->assertTrue(DB::table('chat_conversations')->where('slug', 'general')->exists(), 'general channel missing');
        $this->assertTrue(DB::table('chat_conversations')->where('slug', 'support')->exists(), 'support channel missing');
        $billing = DB::table('chat_conversations')->where('slug', 'billing')->first();
        $this->assertNotNull($billing, 'billing channel missing');
        $this->assertEquals(1, (int) $billing->is_private, 'billing must be private');

        // Threaded message: at least one with parent_id not null
        $this->assertGreaterThanOrEqual(1, DB::table('chat_conversation_messages')->whereNotNull('parent_id')->count(), 'no threaded replies');

        // Entity links point at existing rows and whitelisted types
        foreach (DB::table('message_entity_links')->get() as $link) {
            $this->assertContains($link->linkable_type, array_values(\App\Models\MessageEntityLink::LINKABLE_TYPES));
        }
    }

    public function test_chat_seed_is_idempotent(): void
    {
        $this->seed();
        $first = [
            'c' => DB::table('chat_conversations')->count(),
            'm' => DB::table('chat_conversation_messages')->count(),
            'p' => DB::table('chat_participants')->count(),
            'r' => DB::table('chat_reactions')->count(),
            'l' => DB::table('message_entity_links')->count(),
        ];

        $this->seed();
        $second = [
            'c' => DB::table('chat_conversations')->count(),
            'm' => DB::table('chat_conversation_messages')->count(),
            'p' => DB::table('chat_participants')->count(),
            'r' => DB::table('chat_reactions')->count(),
            'l' => DB::table('message_entity_links')->count(),
        ];

        $this->assertSame($first, $second, 'Second seed changed counts: '.json_encode([$first, $second]));
    }
}
