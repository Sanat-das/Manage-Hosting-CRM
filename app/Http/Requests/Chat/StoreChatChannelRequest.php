<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * Creating a channel.
 *
 * `type` is deliberately absent: the conversation type is decided by the
 * endpoint, never by the request. Accepting it would let a caller post a
 * customer_inbox into the staff channel list.
 */
class StoreChatChannelRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _\-]*$/'],
            'is_private' => ['sometimes', 'boolean'],
            'department' => ['nullable', 'string', 'max:100'],
            'topic' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'A channel name starts with a letter or number and may contain spaces, hyphens and underscores.',
        ];
    }
}
