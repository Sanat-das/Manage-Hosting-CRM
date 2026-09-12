<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Events\Chat\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\ClientChatMessageRequest;
use App\Http\Requests\Chat\RateChatRequest;
use App\Http\Requests\Chat\StartClientChatRequest;
use App\Http\Requests\Chat\StoreChatAttachmentRequest;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\User;
use App\Services\ChatService;
use App\Support\ChatMessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The customer-facing side of the chat.
 *
 * Two kinds of caller reach these endpoints: a signed-in customer, and a
 * visitor with no account at all. Both are authorised the same way — by
 * proving they belong to the one conversation they are asking about — and
 * neither can reach anything else. There is deliberately no endpoint here that
 * lists conversations, so a customer cannot discover that other conversations,
 * or staff channels, exist.
 *
 * The guest token is kept in the session as well as returned to the browser, so
 * a reload does not orphan the conversation, and so the token never has to be
 * trusted from the request body when a session already proves it.
 *
 * Nothing here ever reads an author, a sender type or a user id from the
 * request. Who is speaking is derived from the session or from the token that
 * conversation was opened with, which is what makes posting as an operator
 * impossible rather than merely unlikely.
 */
class ChatWidgetController extends Controller
{
    /** Session key holding the guest's token for the conversation below. */
    public const SESSION_TOKEN = 'chat.guest_token';

    public const SESSION_CONVERSATION = 'chat.conversation_id';

    /** Where the widget puts the token when the session cannot carry it. */
    private const TOKEN_HEADER = 'X-Chat-Token';

    /** Newest messages returned by one fetch. */
    private const MESSAGE_LIMIT = 100;

    public function __construct(private readonly ChatService $chat) {}

    public function start(StartClientChatRequest $request): JsonResponse
    {
        // A double-submitted intro form, or a reload that races the widget's own
        // state, must not mint a second conversation and strand the first in the
        // operator queue. An open conversation in this session is the one we
        // already have.
        $existing = $this->conversationInSession($request);

        if ($existing !== null && $existing->status !== ChatConversation::STATUS_CLOSED) {
            return response()->json([
                'conversation_id' => $existing->id,
                'token' => $existing->guest_token,
                'status' => $existing->status,
            ], 200);
        }

        $customer = $request->user()?->customer;

        try {
            $conversation = $this->chat->startCustomerChat(
                $customer !== null
                    ? ['customer_id' => $customer->id]
                    : ['name' => $request->input('name'), 'email' => $request->input('email')],
                $request->input('department'),
                $request->string('body')->toString(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $request->session()->put(self::SESSION_TOKEN, $conversation->guest_token);
        $request->session()->put(self::SESSION_CONVERSATION, $conversation->id);

        return response()->json([
            'conversation_id' => $conversation->id,
            // The browser needs it to authorise its websocket subscription.
            'token' => $conversation->guest_token,
            'status' => $conversation->status,
        ], 201);
    }

    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authoriseParticipation($request, $conversation);

        $messages = $conversation->messages()
            ->withTrashed()
            ->whereNull('parent_id')
            ->with(['user', 'attachments', 'entityLinks.linkable'])
            ->when($request->integer('after_id'), fn ($q, $after) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get();

        return response()->json([
            'status' => $conversation->status,
            'rating' => $conversation->rating,
            'messages' => $messages->map(fn ($m) => $this->clientPayload($m))->all(),
        ]);
    }

    public function send(ClientChatMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $this->authoriseParticipation($request, $conversation);

        if ($conversation->status === ChatConversation::STATUS_CLOSED) {
            return response()->json(['message' => 'This conversation has been closed.'], 422);
        }

        $author = $this->authorFor($request, $conversation);

        try {
            $message = $this->chat->sendMessage(
                $conversation,
                $author,
                $request->string('body')->toString(),
                null,
                // Always the conversation's own token, never one off the wire.
                $author === null ? $conversation->guest_token : null,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message->load(['user', 'attachments', 'entityLinks.linkable']);

        return response()->json(['message' => $this->clientPayload($message)], 201);
    }

    /**
     * Hang a file off a message the caller has just posted.
     *
     * Two gates, not one: belonging to the conversation lets you read it, but
     * only the author of that particular message may attach to it — otherwise a
     * customer could staple a file onto the operator's reply.
     */
    public function attach(StoreChatAttachmentRequest $request, ChatConversation $conversation, ChatConversationMessage $message): JsonResponse
    {
        $this->authoriseParticipation($request, $conversation);

        abort_unless($message->conversation_id === $conversation->id, 404);

        if ($conversation->status === ChatConversation::STATUS_CLOSED) {
            return response()->json(['message' => 'This conversation has been closed.'], 422);
        }

        $author = $this->authorFor($request, $conversation);

        $isOwnMessage = $author === null
            ? $message->user_id === null
            : $message->user_id === $author->id;

        abort_unless($isOwnMessage, 403);

        $attachment = $this->chat->attachFile($message, $request->file('file'));

        return response()->json(['attachment' => $this->clientAttachment($conversation, $attachment)], 201);
    }

    /**
     * Serve an attachment back to the customer.
     *
     * The admin download route signs its URLs and re-checks the staff policy;
     * neither is any use to a guest, so the customer gets a plain URL that is
     * authorised on every request by the same participation check as the
     * transcript it appears in.
     */
    public function attachment(Request $request, ChatConversation $conversation, ChatMessageAttachment $attachment): BinaryFileResponse
    {
        $this->authoriseParticipation($request, $conversation);

        $attachment->loadMissing('message');

        abort_unless($attachment->message?->conversation_id === $conversation->id, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return response()->download($attachment->absolutePath(), (string) $attachment->filename);
    }

    /**
     * "The customer is typing" for the operator's pane.
     *
     * Broadcast only — nothing is stored, and the payload carries no text. The
     * customer's own identity on this channel is the conversation, not a user
     * id: a guest has none, and inventing one would put a fake row-shaped id on
     * a presence channel staff read.
     */
    public function typing(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authoriseParticipation($request, $conversation);

        TypingIndicator::dispatch(
            $conversation->id,
            0,
            $conversation->displayName(),
            $request->boolean('typing'),
        );

        return response()->json(['ok' => true]);
    }

    public function rate(RateChatRequest $request, ChatConversation $conversation): JsonResponse
    {
        $this->authoriseParticipation($request, $conversation);

        try {
            $this->chat->rateConversation($conversation, $request->integer('rating'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['rating' => $conversation->fresh()->rating]);
    }

    /**
     * Prove the caller belongs to this conversation.
     *
     * A signed-in customer is matched on the conversation's customer_id. A guest
     * is matched on the token in their session, or on the one the widget sends
     * in a header — either will do, because a session left over from an earlier
     * conversation must not lock the widget out of the current one.
     *
     * Anything that is not a customer inbox is refused outright, so this method
     * can never be turned into a way into a staff channel.
     *
     * The two failures are deliberately different codes: a caller with no
     * session at all is unauthenticated (401), while a caller who IS signed in
     * and simply does not own this conversation is forbidden (403) — telling
     * them to authenticate again would be a lie.
     */
    private function authoriseParticipation(Request $request, ChatConversation $conversation): void
    {
        abort_unless($conversation->isCustomerInbox(), 403);

        if ($this->authorFor($request, $conversation) !== null) {
            return;
        }

        foreach ($this->tokenCandidates($request) as $candidate) {
            if ($this->chat->guestTokenMatches($conversation, $candidate)) {
                return;
            }
        }

        abort_if($request->user() !== null, 403);

        abort(response()->json(['message' => 'Unauthenticated.'], 401));
    }

    /**
     * The signed-in customer this message should be attributed to, or null for
     * a guest. A signed-in user who does not own the conversation is NOT its
     * author even when a token got them in, so their name never lands on a
     * stranger's transcript.
     */
    private function authorFor(Request $request, ChatConversation $conversation): ?User
    {
        $user = $request->user();
        $customer = $user?->customer;

        if ($customer === null || $conversation->customer_id !== $customer->id) {
            return null;
        }

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function tokenCandidates(Request $request): array
    {
        return array_values(array_filter([
            $request->session()->get(self::SESSION_TOKEN),
            $request->header(self::TOKEN_HEADER),
        ], static fn ($token) => is_string($token) && $token !== ''));
    }

    private function conversationInSession(Request $request): ?ChatConversation
    {
        $id = $request->session()->get(self::SESSION_CONVERSATION);

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $conversation = ChatConversation::find((int) $id);

        if ($conversation === null || ! $conversation->isCustomerInbox()) {
            return null;
        }

        // The session id alone is not the credential — the token still has to
        // match, or a fixated session id would be a way into someone else's
        // conversation.
        return $this->authorFor($request, $conversation) !== null
            || $this->chat->guestTokenMatches($conversation, $request->session()->get(self::SESSION_TOKEN))
                ? $conversation
                : null;
    }

    /**
     * The customer's view of a message.
     *
     * Entity cards are stripped of their links: an internal reference to a
     * product or ticket may be mentioned in the conversation, and the customer
     * sees the card, but the admin URL is not theirs to have and would 403
     * anyway. That is also what makes the card read-only — there is no client
     * endpoint that creates one.
     *
     * @return array<string, mixed>
     */
    private function clientPayload(ChatConversationMessage $message): array
    {
        $payload = ChatMessagePayload::for($message);

        $payload['entity_links'] = array_map(
            static fn (array $link) => ['type' => $link['type'], 'label' => $link['label']],
            $payload['entity_links'],
        );

        $payload['attachments'] = array_map(
            fn (array $attachment) => $this->clientAttachmentUrl($message->conversation_id, $attachment),
            $payload['attachments'],
        );

        // That a staff member said it is enough; which staff member, and their
        // user id, is not the customer's business.
        $payload['is_operator'] = ! $payload['is_guest'];
        unset($payload['user']);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function clientAttachment(ChatConversation $conversation, ChatMessageAttachment $attachment): array
    {
        return $this->clientAttachmentUrl($conversation->id, ChatMessagePayload::attachment($attachment));
    }

    /**
     * Swap the signed admin download URL for the customer's own one.
     *
     * @param  array<string, mixed>  $attachment
     * @return array<string, mixed>
     */
    private function clientAttachmentUrl(int $conversationId, array $attachment): array
    {
        $attachment['url'] = route('chat.attachment', [
            'conversation' => $conversationId,
            'attachment' => $attachment['id'],
        ]);

        return $attachment;
    }
}
