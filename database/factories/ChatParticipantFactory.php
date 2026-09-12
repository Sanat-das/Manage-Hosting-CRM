<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatParticipant>
 */
class ChatParticipantFactory extends Factory
{
    protected $model = ChatParticipant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'user_id' => User::factory(),
            'role' => ChatParticipant::ROLE_MEMBER,
            'joined_at' => now(),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => ChatParticipant::ROLE_ADMIN]);
    }
}
