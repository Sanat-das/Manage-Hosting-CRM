<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Services\ChatEntitySearch;
use Illuminate\Validation\Rule;

/**
 * Attaching an entity reference to a message.
 *
 * The type must be one of the whitelisted keys. Whether the row exists, and
 * whether this user may read that kind of record at all, is settled in the
 * controller and the service.
 */
class StoreChatEntityLinkRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(ChatEntitySearch::PERMISSIONS))],
            'id' => ['required', 'integer', 'min:1'],
        ];
    }
}
