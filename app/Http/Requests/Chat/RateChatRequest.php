<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * The customer's star rating once a conversation is over.
 */
class RateChatRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
        ];
    }
}
