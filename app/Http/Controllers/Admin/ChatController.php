<?php

namespace App\Http\Controllers\Admin;

use App\Events\Chat\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SearchChatEntitiesRequest;
use App\Http\Requests\Chat\StoreChatAttachmentRequest;
use App\Http\Requests\Chat\StoreChatChannelRequest;
use App\Http\Requests\Chat\StoreChatEntityLinkRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\ToggleChatReactionRequest;
use App\Http\Requests\Chat\TypingHeartbeatRequest;
use App\Http\Requests\Chat\UpdateChatChannelRequest;
use App\Http\Requests\Chat\UpdateChatMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatReaction;
use App\Models\ChatSession;
use App\Models\MessageEntityLink;
use App\Models\User;
use App\Services\ChatEntitySearch;
use App\Services\ChatPresence;
use App\Services\ChatService;
use App\Services\TicketService;
use App\Support\ChatMessagePayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin live chat.
 *
 * index/show still render the legacy chat_sessions screens; they are replaced
 * by the Slack-like layout in a later step. Everything below them is the write
 * path for the new engine.
 *
 * Two layers guard every write: the route's `permission:` middleware decides
 * whether you may touch the chat at all, and ChatConversationPolicy decides
 * whether you may touch *this* conversation. Neither is sufficient alone —
 * holding chat.view does not make a private channel yours.
 */
class ChatController extends Controller
{
    /** Newest-first page size for a conversation's history. */
    private const MESSAGE_PAGE = 50;

    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatPresence $presence,
    ) {}

    /**
     * The Slack-like workspace: sidebar, message pane, thread panel, composer.
     *
     * Rendered server-side with the first page of history already in it, so the
     * conversation is readable before any websocket connects — and still
     * readable if none ever does.
     */
    public function index(Request $request, ChatEntitySearch $entities): View
    {
        $user = $request->user();

        $conversations = $this->visibleConversations($user);
        $unread = $this->chat->unreadCounts($user);

        $selected = $this->resolveSelected($request, $conversations);
        $messages = collect();

        if ($selected !== null) {
            $messages = $selected->messages()
                ->withTrashed()
                ->whereNull('parent_id')
                ->with(['user', 'attachments', 'entityLinks.linkable'])
                ->orderByDesc('id')
                ->limit(self::MESSAGE_PAGE)
                ->get()
                ->reverse()
                ->values();

            $this->chat->markRead($selected, $user);
            $unread[$selected->id] = 0;
        }

        return view('admin.chat.index', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages->map(fn ($m) => ChatMessagePayload::for($m)),
            'unread' => $unread,
            'online' => $this->presence->online(),
            'canCreateChannel' => $user->can('create', ChatConversation::class),
            'canOperate' => $user->hasPermission('chat.manage'),
            'entityTypes' => $entities->availableTypes($user),
            'mentionables' => $selected === null ? collect() : $this->mentionablesFor($selected),
            'emojis' => ChatReaction::ALLOWED,
            'heartbeatSeconds' => ChatPresence::HEARTBEAT_SECONDS,
        ]);
    }

    /**
     * Everything this user may see, grouped the way the sidebar shows it.
     *
     * Private rooms are filtered in SQL by participation rather than loaded and
     * then rejected by the policy — the policy is the authority, but a sidebar
     * that queries every private channel in the install to discard most of them
     * gets slower with every channel anyone creates.
     *
     * @return Collection<string, Collection<int, ChatConversation>>
     */
    private function visibleConversations(User $user): Collection
    {
        $canOperate = $user->hasPermission('chat.manage');

        $all = ChatConversation::query()
            ->notArchived()
            ->where(function ($q) use ($user, $canOperate) {
                // Public channels, visible to every chat user.
                $q->where(fn ($p) => $p->where('type', ChatConversation::TYPE_CHANNEL)->where('is_private', false));

                // Anything at all that this user is a participant of.
                $q->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));

                // The customer queue, for operators.
                if ($canOperate) {
                    $q->orWhere('type', ChatConversation::TYPE_CUSTOMER_INBOX);
                }
            })
            ->with(['participants.user', 'customer.user', 'assignedOperator'])
            ->orderBy('name')
            ->orderByDesc('id')
            ->get()
            // Department scoping and private-channel membership are the policy's
            // call; the query above is only a cheap pre-filter.
            ->filter(fn (ChatConversation $c) => $user->can('view', $c))
            ->values();

        return collect([
            'channels' => $all->where('type', ChatConversation::TYPE_CHANNEL)->values(),
            'dms' => $all->whereIn('type', [ChatConversation::TYPE_DM, ChatConversation::TYPE_GROUP_DM])->values(),
            'inbox' => $all->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)->values(),
        ]);
    }

    /**
     * Which conversation `?c=` asks for, or the first one in the sidebar.
     *
     * Two rules, both learned the hard way:
     *
     * 1. The sidebar listing is a listing, not an authorisation source. It hides
     *    archived rooms deliberately, and "not in the list" was being read as
     *    "not permitted" — which made an archived conversation 403 on this page
     *    while the JSON history of the same room answered 200 to the same user.
     *    Archive is a freeze, not a deletion: readable, not postable. The policy
     *    already says exactly that (`view()` has no archived check, and
     *    `sendMessage()` does), so ask the policy.
     *
     * 2. Every id the caller may not open gets ONE answer: a 404. Nonexistent,
     *    malformed and real-but-forbidden must be indistinguishable, or the
     *    difference between them enumerates every conversation in the install.
     *    404 keeps the original intent — refuse, never silently fall back to
     *    some other room — while saying nothing about whether the row exists.
     *
     * @param  Collection<string, Collection<int, ChatConversation>>  $conversations
     */
    private function resolveSelected(Request $request, Collection $conversations): ?ChatConversation
    {
        $flat = $conversations->flatten();
        $requested = $request->query('c');

        // No selection asked for. An empty `c` is a form submitting nothing,
        // not a lookup of a conversation named "".
        if ($requested === null || $requested === '') {
            return $flat->first();
        }

        // is_numeric() rather than a cast: `?c[]=1` must not reach an int cast,
        // and `?c=abc` must become a miss rather than conversation 0.
        $id = is_numeric($requested) ? (int) $requested : 0;

        // Already in the sidebar: loaded, and already policy-filtered there.
        if ($found = $flat->firstWhere('id', $id)) {
            return $found;
        }

        $conversation = ChatConversation::query()
            ->with(['participants.user', 'customer.user', 'assignedOperator'])
            ->find($id);

        abort_if($conversation === null, 404);
        abort_unless($request->user()->can('view', $conversation), 404);

        return $conversation;
    }

    /**
     * Who @mention autocomplete may offer in this conversation.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function mentionablesFor(ChatConversation $conversation): Collection
    {
        return $conversation->participants
            ->map(fn ($p) => $p->user)
            ->filter()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->full_name, 'email' => $u->email])
            ->values();
    }

    public function show(ChatSession $chat): View
    {
        $chat->load(['messages' => fn ($q) => $q->orderBy('created_at')]);

        return view('admin.chat.show', ['chat' => $chat]);
    }

    // --- channels ---------------------------------------------------------

    public function storeChannel(StoreChatChannelRequest $request): JsonResponse
    {
        Gate::authorize('create', ChatConversation::class);

        try {
            $channel = $this->chat->createChannel(
                $request->string('name')->toString(),
                $request->user(),
                $request->boolean('is_private'),
                $request->input('department'),
                $request->input('topic'),
                $request->input('purpose'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['channel' => $this->channelPayload($channel)], 201);
    }

    public function updateChannel(UpdateChatChannelRequest $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('update', $conversation);

        $conversation->fill($request->only(['name', 'topic', 'purpose']))->save();

        return response()->json(['channel' => $this->channelPayload($conversation)]);
    }

    public function archiveChannel(ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('archive', $conversation);

        $this->chat->archive($conversation);

        return response()->json(['channel' => $this->channelPayload($conversation->fresh())]);
    }

    public function unarchiveChannel(ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('archive', $conversation);

        $this->chat->unarchive($conversation);

        return response()->json(['channel' => $this->channelPayload($conversation->fresh())]);
    }

    public function joinChannel(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('join', $conversation);

        $this->chat->addMember($conversation, $request->user());

        return response()->json(['joined' => true]);
    }

    public function leaveChannel(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('leave', $conversation);

        $this->chat->removeMember($conversation, $request->user());

        return response()->json(['joined' => false]);
    }

    public function addMember(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('addMember', $conversation);

        $validated = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        try {
            $this->chat->addMember($conversation, User::findOrFail($validated['user_id']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['members' => $conversation->participants()->count()], 201);
    }

    public function removeMember(Request $request, ChatConversation $conversation, User $user): JsonResponse
    {
        Gate::authorize('removeMember', $conversation);

        $this->chat->removeMember($conversation, $user);

        return response()->json(['members' => $conversation->participants()->count()]);
    }

    // --- messages ---------------------------------------------------------

    /**
     * A page of history, newest first.
     *
     * `before_id` pages backwards for "load older"; `after_id` is what the
     * polling fallback uses when the websocket is down.
     */
    public function fetchMessages(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $query = $conversation->messages()
            ->withTrashed()
            ->whereNull('parent_id')
            ->with(['user', 'attachments', 'entityLinks.linkable'])
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE);

        if ($before = $request->integer('before_id')) {
            $query->where('id', '<', $before);
        }

        if ($after = $request->integer('after_id')) {
            $query->where('id', '>', $after);
        }

        $messages = $query->get()->reverse()->values();

        return response()->json([
            'messages' => $messages->map(fn ($m) => ChatMessagePayload::for($m))->all(),
            'has_more' => $messages->isNotEmpty()
                && $conversation->messages()->whereNull('parent_id')->where('id', '<', $messages->first()->id)->exists(),
        ]);
    }

    public function storeMessage(StoreChatMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('sendMessage', $conversation);

        try {
            $message = $this->chat->sendMessage(
                $conversation,
                $request->user(),
                $request->string('body')->toString(),
                $request->input('parent_id') === null ? null : (int) $request->input('parent_id'),
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message->load(['user', 'attachments', 'entityLinks.linkable']);

        return response()->json(['message' => ChatMessagePayload::for($message)], 201);
    }

    public function updateMessage(UpdateChatMessageRequest $request, ChatConversationMessage $message): JsonResponse
    {
        Gate::authorize('update', $message);

        try {
            $this->chat->editMessage($message, $request->string('body')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message->load(['user', 'attachments', 'entityLinks.linkable']);

        return response()->json(['message' => ChatMessagePayload::for($message)]);
    }

    public function destroyMessage(ChatConversationMessage $message): JsonResponse
    {
        Gate::authorize('delete', $message);

        $this->chat->deleteMessage($message);

        return response()->json(['deleted' => true]);
    }

    /**
     * Every reply under one thread root, oldest first.
     */
    public function fetchThread(ChatConversation $conversation, ChatConversationMessage $parent): JsonResponse
    {
        Gate::authorize('view', $conversation);

        abort_unless($parent->conversation_id === $conversation->id, 404);

        $replies = $parent->replies()
            ->withTrashed()
            ->with(['user', 'attachments', 'entityLinks.linkable'])
            ->get();

        return response()->json([
            'parent' => ChatMessagePayload::for($parent->load(['user', 'attachments', 'entityLinks.linkable'])),
            'replies' => $replies->map(fn ($m) => ChatMessagePayload::for($m))->all(),
        ]);
    }

    public function toggleReaction(ToggleChatReactionRequest $request, ChatConversationMessage $message): JsonResponse
    {
        Gate::authorize('react', $message);

        try {
            $result = $this->chat->toggleReaction($message, $request->user(), $request->string('emoji')->toString());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    // --- entity references ------------------------------------------------

    /**
     * Typeahead for the composer's attach picker.
     */
    public function searchEntities(SearchChatEntitiesRequest $request, ChatEntitySearch $search): JsonResponse
    {
        $validated = $request->validated();

        // Asking for a type you cannot read is a 403, not an empty list: an
        // empty list is indistinguishable from "no matches" and quietly hides
        // the fact that the answer was refused.
        if (isset($validated['type']) && ! $search->allows($request->user(), $validated['type'])) {
            abort(403);
        }

        return response()->json([
            'results' => $search->search($request->user(), $validated['q'], $validated['type'] ?? null),
            'types' => $search->availableTypes($request->user()),
        ]);
    }

    public function storeEntityLink(StoreChatEntityLinkRequest $request, ChatConversationMessage $message, ChatEntitySearch $search): JsonResponse
    {
        Gate::authorize('update', $message);

        $validated = $request->validated();

        // Linking is a read of the target, so it needs the same permission the
        // search does — otherwise the picker's gate is bypassed by guessing ids.
        abort_unless($search->allows($request->user(), $validated['type']), 403);

        try {
            $this->chat->linkEntity($message, $validated['type'], (int) $validated['id']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'That record no longer exists.'], 404);
        }

        $message->load(['user', 'attachments', 'entityLinks.linkable']);

        return response()->json(['message' => ChatMessagePayload::for($message)], 201);
    }

    public function destroyEntityLink(ChatConversationMessage $message, MessageEntityLink $link): JsonResponse
    {
        Gate::authorize('update', $message);

        abort_unless($link->message_id === $message->id, 404);

        $this->chat->unlinkEntity($link);

        return response()->json(['deleted' => true]);
    }

    // --- customer inbox (operator side) -----------------------------------

    /**
     * The operator queue: waiting and active customer conversations.
     */
    public function inbox(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ChatConversation::class);
        abort_unless($request->user()->hasPermission('chat.manage'), 403);

        $conversations = ChatConversation::query()
            ->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->with(['customer.user', 'assignedOperator'])
            ->orderByRaw("case status when 'waiting' then 0 when 'active' then 1 else 2 end")
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'conversations' => $conversations->map(fn (ChatConversation $c) => $this->inboxPayload($c))->all(),
        ]);
    }

    public function assignOperator(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('operate', $conversation);

        $validated = $request->validate(['user_id' => ['nullable', 'integer', 'exists:users,id']]);

        $operator = isset($validated['user_id'])
            ? User::findOrFail($validated['user_id'])
            : $request->user();

        try {
            // The acting user is passed explicitly. Without it the service
            // defaults the actor to the operator, every assignment looks like a
            // self-assignment, and the assignee is never told — which is exactly
            // what happened here while the service's own tests stayed green.
            $this->chat->assignOperator($conversation, $operator, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['conversation' => $this->inboxPayload($conversation->fresh())]);
    }

    public function transferConversation(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('operate', $conversation);

        $validated = $request->validate(['department' => ['required', 'string', 'max:100']]);

        try {
            $this->chat->transferConversation($conversation, $validated['department']);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['conversation' => $this->inboxPayload($conversation->fresh())]);
    }

    public function closeConversation(ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('operate', $conversation);

        $this->chat->closeConversation($conversation);

        return response()->json(['conversation' => $this->inboxPayload($conversation->fresh())]);
    }

    public function convertToTicket(ChatConversation $conversation, TicketService $tickets): JsonResponse
    {
        Gate::authorize('operate', $conversation);

        try {
            $ticket = $this->chat->convertToTicket($conversation, $tickets);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ticket' => [
                'id' => $ticket->id,
                'ticket_no' => $ticket->ticket_no,
                'url' => route('admin.tickets.show', $ticket),
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboxPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'name' => $conversation->displayName(),
            'status' => $conversation->status,
            'department' => $conversation->department,
            'customer_id' => $conversation->customer_id,
            'guest_email' => $conversation->guest_email,
            'operator' => $conversation->assignedOperator === null ? null : [
                'id' => $conversation->assignedOperator->id,
                'name' => $conversation->assignedOperator->full_name,
            ],
            'rating' => $conversation->rating,
            'closed_at' => $conversation->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Attach a file to a message you just posted.
     */
    public function storeAttachment(StoreChatAttachmentRequest $request, ChatConversationMessage $message): JsonResponse
    {
        // Attaching is part of authoring, so it is the edit gate, not the read
        // gate: you may not hang a file off somebody else's message.
        Gate::authorize('update', $message);

        $attachment = $this->chat->attachFile(
            $message,
            $request->file('file'),
            $request->boolean('inline'),
        );

        return response()->json(['attachment' => ChatMessagePayload::attachment($attachment)], 201);
    }

    /**
     * Serve an attachment.
     *
     * The URL is signed, but the signature is not the authorisation — a signed
     * link that leaked would otherwise be a permanent read token for a private
     * conversation. The policy is checked on every request as well, so the
     * signature only bounds how long a URL is usable.
     */
    public function showAttachment(Request $request, ChatMessageAttachment $attachment): Response
    {
        $message = $attachment->message;

        abort_if($message === null, 404);

        Gate::authorize('view', $message);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404, 'Attachment file is missing.');

        $mime = $attachment->mime_type ?: 'application/octet-stream';

        // SVG is in the upload whitelist because people paste diagrams, but it
        // is script-capable markup: never rendered inline, always downloaded.
        $previewable = $attachment->isImage()
            && $mime !== 'image/svg+xml';

        if ($previewable && ! $request->boolean('download')) {
            return response($disk->get($attachment->path), 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->filename).'"',
                'Content-Length' => (string) $disk->size($attachment->path),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        return $disk->download($attachment->path, $attachment->filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Move this user's read cursor in one conversation.
     */
    public function markRead(Request $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        $validated = $request->validate(['message_id' => ['nullable', 'integer']]);

        $cursor = $this->chat->markRead(
            $conversation,
            $request->user(),
            $validated['message_id'] ?? null,
        );

        return response()->json([
            'last_read_message_id' => $cursor,
            'unread' => $this->chat->unreadCount($conversation, $request->user()),
        ]);
    }

    /**
     * Unread badges for the whole sidebar, plus who is online.
     *
     * This is also the polling fallback: when the websocket is down the client
     * asks here on a timer instead of being told.
     */
    public function unread(Request $request): JsonResponse
    {
        return response()->json([
            'unread' => $this->chat->unreadCounts($request->user()),
            'online' => $this->presence->online(),
        ]);
    }

    /**
     * "I am still here" from an open tab, or an explicit goodbye.
     */
    public function presenceHeartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate(['online' => ['sometimes', 'boolean']]);

        if (($validated['online'] ?? true) === false) {
            $this->presence->leave($request->user());
        } else {
            $this->presence->heartbeat($request->user());
        }

        return response()->json([
            'online' => $this->presence->online(),
            'heartbeat_seconds' => ChatPresence::HEARTBEAT_SECONDS,
        ]);
    }

    /**
     * Typing is broadcast, never stored — it is worthless a second later.
     */
    public function typingHeartbeat(TypingHeartbeatRequest $request, ChatConversation $conversation): JsonResponse
    {
        Gate::authorize('sendMessage', $conversation);

        TypingIndicator::dispatch(
            (int) $conversation->id,
            (int) $request->user()->id,
            $request->user()->full_name,
            $request->boolean('typing', true),
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPayload(ChatConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'name' => $conversation->displayName(),
            'slug' => $conversation->slug,
            'is_private' => (bool) $conversation->is_private,
            'department' => $conversation->department,
            'topic' => $conversation->topic,
            'purpose' => $conversation->purpose,
            'is_archived' => $conversation->isArchived(),
        ];
    }
}
