<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatService;

/**
 * Opening a conversation from the customer widget.
 *
 * Name and email are required only for a visitor with no account; a signed-in
 * customer is identified by their session and anything they send in those
 * fields is ignored by the controller.
 */
class StartClientChatRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isGuest = $this->user()?->customer === null;

        return [
            'name' => [$isGuest ? 'required' : 'nullable', 'string', 'max:100'],
            'email' => [$isGuest ? 'required' : 'nullable', 'email', 'max:190'],
            'department' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:'.ChatService::MAX_BODY_LENGTH],
        ];
    }
}
