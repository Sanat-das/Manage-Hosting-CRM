<?php

namespace Database\Factories;

use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChatMessageAttachment>
 */
class ChatMessageAttachmentFactory extends Factory
{
    protected $model = ChatMessageAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = fake()->word().'.png';

        return [
            'message_id' => ChatConversationMessage::factory(),
            'disk' => 'local',
            'path' => 'chat-attachments/'.Str::random(20).'/'.$filename,
            'filename' => $filename,
            'mime_type' => 'image/png',
            'size_bytes' => fake()->numberBetween(1024, 512 * 1024),
            'is_inline' => false,
        ];
    }

    public function inline(): static
    {
        return $this->state(fn () => ['is_inline' => true]);
    }

    public function pdf(): static
    {
        return $this->state(function () {
            $filename = fake()->word().'.pdf';

            return [
                'filename' => $filename,
                'mime_type' => 'application/pdf',
                'path' => 'chat-attachments/'.Str::random(20).'/'.$filename,
            ];
        });
    }
}
