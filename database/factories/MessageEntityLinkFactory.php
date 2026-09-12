<?php

namespace Database\Factories;

use App\Models\ChatConversationMessage;
use App\Models\MessageEntityLink;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<MessageEntityLink>
 */
class MessageEntityLinkFactory extends Factory
{
    protected $model = MessageEntityLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => ChatConversationMessage::factory(),
            'linkable_type' => MessageEntityLink::LINKABLE_TYPES['ticket'],
            'linkable_id' => 1,
            'created_at' => now(),
        ];
    }

    public function for_(Model $entity): static
    {
        return $this->state(fn () => [
            'linkable_type' => $entity::class,
            'linkable_id' => $entity->getKey(),
        ]);
    }
}
