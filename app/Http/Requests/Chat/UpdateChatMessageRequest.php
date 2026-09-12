<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatService;

/**
 * Editing one's own message. Only the text may change — a message cannot be
 * moved between conversations or re-parented into a different thread.
 */
class UpdateChatMessageRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.ChatService::MAX_BODY_LENGTH],
        ];
    }
}
