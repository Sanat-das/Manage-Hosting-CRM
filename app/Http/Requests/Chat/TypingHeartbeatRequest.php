<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * "I am typing" / "I stopped".
 */
class TypingHeartbeatRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'typing' => ['sometimes', 'boolean'],
        ];
    }
}
