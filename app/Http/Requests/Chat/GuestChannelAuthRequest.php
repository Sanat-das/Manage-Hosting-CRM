<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

/**
 * A guest asking to subscribe to their own conversation's channel.
 *
 * Shape only. Whether the token is the right one for that conversation — and
 * whether the channel is even one a guest may ask for — is decided in the
 * controller, which is where the conversation is resolved.
 */
class GuestChannelAuthRequest extends ChatFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel_name' => ['required', 'string', 'max:255'],
            'socket_id' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string', 'size:40'],
        ];
    }
}
