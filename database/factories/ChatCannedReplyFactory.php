<?php

namespace Database\Factories;

use App\Models\ChatCannedReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatCannedReply>
 */
class ChatCannedReplyFactory extends Factory
{
    protected $model = ChatCannedReply::class;

    /**
     * Shared by default, because that is the library most tests are about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'shortcut' => $this->faker->unique()->lexify('????????'),
            'body' => $this->faker->paragraph(),
            'department' => null,
            'user_id' => null,
            'created_by' => User::factory(),
        ];
    }

    /** One person's own reply, invisible to everyone else. */
    public function ownedBy(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'created_by' => $user->id,
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }
}
