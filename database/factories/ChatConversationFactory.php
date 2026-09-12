<?php

namespace Database\Factories;

use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatConversation>
 */
class ChatConversationFactory extends Factory
{
    protected $model = ChatConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'type' => ChatConversation::TYPE_CHANNEL,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_private' => false,
            'department' => null,
            'topic' => fake()->optional()->sentence(4),
            'purpose' => null,
            'created_by' => User::factory(),
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['is_private' => true]);
    }

    public function dm(): static
    {
        return $this->state(fn () => [
            'type' => ChatConversation::TYPE_DM,
            'name' => null,
            'slug' => null,
        ]);
    }

    public function groupDm(): static
    {
        return $this->state(fn () => [
            'type' => ChatConversation::TYPE_GROUP_DM,
            'name' => null,
            'slug' => null,
        ]);
    }

    public function customerInbox(): static
    {
        return $this->state(fn () => [
            'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
            'name' => null,
            'slug' => null,
            'status' => ChatConversation::STATUS_WAITING,
            'department' => 'support',
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
