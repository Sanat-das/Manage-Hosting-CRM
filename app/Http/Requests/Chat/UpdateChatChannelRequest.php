<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * Renaming a channel or changing its topic/purpose.
 *
 * Neither `type` nor `is_private` can be changed here: flipping a private
 * channel to public would retroactively expose everything ever said in it.
 */
class UpdateChatChannelRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _\-]*$/'],
            'topic' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ];
    }
}
