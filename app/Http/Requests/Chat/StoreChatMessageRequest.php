<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatService;

/**
 * Posting a message, or a reply within a thread.
 */
class StoreChatMessageRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.ChatService::MAX_BODY_LENGTH],
            // Validated against *this* conversation by the service, not by an
            // exists rule: a parent from another conversation exists perfectly
            // well and must still be refused.
            'parent_id' => ['nullable', 'integer'],
        ];
    }
}
