<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Models\ChatReaction;
use Illuminate\Validation\Rule;

/**
 * Toggling an emoji reaction.
 *
 * The emoji is checked against a whitelist rather than a length rule: the
 * column is 32 bytes and arbitrary text in it is a spoofing surface, not a
 * reaction.
 */
class ToggleChatReactionRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(ChatReaction::ALLOWED)],
        ];
    }
}
