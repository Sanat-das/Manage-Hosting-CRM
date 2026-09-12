<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatService;

/**
 * A customer's reply.
 *
 * Only a body. There is deliberately no sender or author field: who sent this
 * is decided from the session or the guest token, never from the request, so a
 * customer cannot post as an operator.
 */
class ClientChatMessageRequest extends ChatFormRequest
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
