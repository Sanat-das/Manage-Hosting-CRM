<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatService;

/**
 * Opening a direct message.
 *
 * Always a list, never a scalar: one id is a 1:1 DM and several are a group
 * DM, and the endpoint decides which from the count. A second `user_id` shape
 * would mean two paths through the same authorisation.
 */
class StoreChatDirectMessageRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.ChatService::MAX_GROUP_DM_PARTICIPANTS],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'name' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Choose somebody to message.',
            'user_ids.max' => 'A group message is capped at '.ChatService::MAX_GROUP_DM_PARTICIPANTS.' people; open a channel instead.',
        ];
    }
}
