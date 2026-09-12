<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatConversationMessage>
 */
class ChatConversationMessageFactory extends Factory
{
    protected $model = ChatConversationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => ChatConversation::factory(),
            'user_id' => User::factory(),
            'parent_id' => null,
            'body' => fake()->sentence(),
        ];
    }

    public function replyTo(ChatConversationMessage $parent): static
    {
        return $this->state(fn () => [
            'conversation_id' => $parent->conversation_id,
            'parent_id' => $parent->id,
        ]);
    }

    public function edited(): static
    {
        return $this->state(fn () => ['edited_at' => now()]);
    }
}
