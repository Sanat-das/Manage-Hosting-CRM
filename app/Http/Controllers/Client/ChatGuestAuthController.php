<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\GuestChannelAuthRequest;
use App\Models\ChatConversation;
use App\Services\ChatService;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Websocket channel authorisation for a customer with no account.
 *
 * The ordinary /broadcasting/auth endpoint authorises from the session, which a
 * guest does not have. This endpoint authorises from the conversation's own
 * guest token instead, and can therefore authorise exactly one thing: that
 * conversation's private channel.
 *
 * It is deliberately NOT a general-purpose broadcaster auth route. It parses
 * the requested channel name itself and refuses anything that is not
 * `private-chat.conversation.{id}` for a customer_inbox whose token matches, so
 * possessing a guest token can never be turned into a subscription to a staff
 * channel.
 */
class ChatGuestAuthController extends Controller
{
    /** The only channel shape a guest may ever ask for. */
    private const CHANNEL_PATTERN = '/^private-chat\.conversation\.(\d+)$/';

    public function __construct(private readonly ChatService $chat) {}

    public function __invoke(GuestChannelAuthRequest $request)
    {
        $validated = $request->validated();

        if (! preg_match(self::CHANNEL_PATTERN, $validated['channel_name'], $matches)) {
            throw new AccessDeniedHttpException;
        }

        $conversation = ChatConversation::find((int) $matches[1]);

        if ($conversation === null
            || ! $conversation->isCustomerInbox()
            || ! $this->chat->guestTokenMatches($conversation, $validated['token'])) {
            throw new AccessDeniedHttpException;
        }

        // Hand the signing back to the configured broadcaster rather than
        // assembling an auth signature by hand.
        return Broadcast::driver()->validAuthenticationResponse($request, true);
    }
}
