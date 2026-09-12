<?php

namespace Database\Factories;

use App\Models\ChatConversationMessage;
use App\Models\ChatReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatReaction>
 */
class ChatReactionFactory extends Factory
{
    protected $model = ChatReaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => ChatConversationMessage::factory(),
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(ChatReaction::ALLOWED),
        ];
    }
}
