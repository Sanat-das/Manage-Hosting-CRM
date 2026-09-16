<?php

namespace App\Http\Controllers\Admin;

use App\Events\Chat\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SearchChatEntitiesRequest;
use App\Http\Requests\Chat\SearchChatMessagesRequest;
use App\Http\Requests\Chat\StoreChatAttachmentRequest;
use App\Http\Requests\Chat\StoreChatChannelRequest;
use App\Http\Requests\Chat\StoreChatEntityLinkRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\ToggleChatReactionRequest;
use App\Http\Requests\Chat\TypingHeartbeatRequest;
use App\Http\Requests\Chat\UpdateChatAvailabilityRequest;
use App\Http\Requests\Chat\UpdateChatChannelRequest;
use App\Http\Requests\Chat\UpdateChatMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatOperatorAvailability;
use App\Models\ChatReaction;
use App\Models\ChatSession;
use App\Models\MessageEntityLink;
use App\Models\User;
use App\Services\ChatAvailability;
use App\Services\ChatEntitySearch;
use App\Services\ChatPresence;
use App\Services\ChatService;
use App\Services\TicketService;
use App\Support\ChatMessagePayload;
use Illuminate\Database\Eloquent\Builder;
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

    /** Newest-first page size for search results. */
    private const SEARCH_PAGE = 30;

    /**
     * How many queued customer conversations the sidebar poll carries.
     *
     * A cap, not a page: this payload is fetched every heartbeat by every open
     * operator tab, and a queue longer than this is a staffing problem that a
     * bigger JSON response does not solve.
     */
    private const INBOX_POLL_LIMIT = 50;

    /**
     * The LIKE escape character for search patterns.
     *
     * Not a backslash: MySQL's default LIKE escape IS a backslash while
     * SQLite has none, and `ESCAPE '\'` cannot be written as one string literal
     * that means the same thing in both. `!` is unremarkable in every dialect,
     * and is itself escaped by likeLiteral() so a literal `!` still matches.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * `$availability` is defaulted rather than required so every existing
     * `new ChatController($chat, $presence)` — in this app and in the tests —
     * keeps working untouched. Same reasoning as ChatService's injected
     * notification gate.
     */
    public function __construct(
        private readonly ChatService $chat,
        private readonly ChatPresence $presence,
        private readonly ChatAvailability $availability = new ChatAvailability,
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

        $online = $this->presence->online();

        return view('admin.chat.index', [
            'conversations' => $conversations,
            'selected' => $selected,
            'messages' => $messages->map(fn ($m) => ChatMessagePayload::for($m)),
            'unread' => $unread,
            'online' => $online,
            // The roster's states, resolved in one query for everyone on it —
            // a badge per person otherwise costs a query per person on every
            // render of this page.
            'availabilityStates' => $this->availability->statesFor(
                array_map(static fn (array $person): int => (int) $person['id'], $online),
            ),
            'availability' => $this->availability->stateFor($user),
            'availabilityOptions' => ChatOperatorAvailability::LABELS,
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
        $all = $this->scopeReadableBy(ChatConversation::query(), $user)
            // The sidebar — and only the sidebar — hides archived rooms. This
            // is a listing preference, not an authorisation rule; search
            // deliberately does NOT apply it (see readableConversationIds).
            ->notArchived()
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
     * The cheap SQL pre-filter for "conversations this user could plausibly
     * read" — one copy, shared by the sidebar and by search.
     *
     * It is a pre-filter, NOT the authority: ChatConversationPolicy::view()
     * still runs over every row it returns. Its only job is to keep the query
     * from loading every private channel in the install in order to throw most
     * of them away in PHP. A second, drifting copy of this clause is exactly
     * how a private room ends up readable somewhere the sidebar would hide it.
     *
     * @param  Builder<ChatConversation>  $query
     * @return Builder<ChatConversation>
     */
    private function scopeReadableBy(Builder $query, User $user): Builder
    {
        $canOperate = $user->hasPermission('chat.manage');

        return $query->where(function ($q) use ($user, $canOperate) {
            // Public channels, visible to every chat user.
            $q->where(fn ($p) => $p->where('type', ChatConversation::TYPE_CHANNEL)->where('is_private', false));

            // Anything at all that this user is a participant of.
            $q->orWhereHas('participants', fn ($p) => $p->where('user_id', $user->id));

            // The customer queue, for operators.
            if ($canOperate) {
                $q->orWhere('type', ChatConversation::TYPE_CUSTOMER_INBOX);
            }
        });
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

    /**
     * Full-text-ish search across everything the caller may read.
     *
     * PERFORMANCE, stated plainly because it is a deliberate choice and not an
     * oversight: the `LIKE '%q%'` below has a LEADING wildcard, so it cannot
     * use any index on `chat_conversation_messages.body` — no index on that
     * column would be usable even if one existed, and the database will scan
     * every row that survives the preceding filters. That is an accepted v1
     * tradeoff at the volume this table is expected to reach, and it is bounded
     * on both sides:
     *
     *   - the conversation whitelist and the date range are applied FIRST, so
     *     the scan runs over one user's readable rows in a bounded window
     *     rather than over the whole table; both sides of that narrowing are
     *     indexed (`conversation_id`, `created_at`);
     *   - the result set is capped by LIMIT 30 (SEARCH_PAGE) per page.
     *
     * Revisit once the table passes roughly 500k rows, by adding a MySQL
     * FULLTEXT index on `body` and switching to MATCH ... AGAINST. That is an
     * added index and a changed WHERE clause — no schema break, no migration of
     * existing rows, and nothing about this endpoint's contract changes.
     *
     * SECURITY: the order of operations in this method is the whole point. The
     * set of conversations the caller may read is resolved BEFORE the LIKE and
     * applied as a `whereIn`, so a non-participant's search cannot reach a
     * private channel's text no matter what they type — including by naming
     * that channel in `?channel=`, which narrows the authorised set and can
     * never widen it.
     */
    public function search(SearchChatMessagesRequest $request): JsonResponse
    {
        $user = $request->user();

        // FIRST: what may this user read at all?
        $conversationIds = $this->readableConversationIds($user);

        // A channel filter narrows that set by intersection. An id the caller
        // may not read — or one that does not exist — leaves nothing to search
        // and is answered with the same empty page, so the response cannot be
        // used to tell "no such conversation" from "not yours".
        if ($channel = $request->integer('channel')) {
            $conversationIds = array_values(array_intersect($conversationIds, [$channel]));
        }

        if ($conversationIds === []) {
            return response()->json($this->emptySearchPage());
        }

        $query = ChatConversationMessage::query()
            ->whereIn('conversation_id', $conversationIds)
            ->with(['user', 'conversation']);

        // Dates next, still ahead of the LIKE. `to` covers the whole day it
        // names — a range of 2026-09-05..2026-09-05 means that Saturday, not
        // the single instant of its midnight.
        if ($from = $request->date('from')) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($to = $request->date('to')) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        // LAST, and only now: the scan.
        //
        // `%` and `_` are LIKE metacharacters. Left unescaped, `q=%` stops
        // being a search and becomes "return every message this user can read",
        // and `q=h_llo` quietly matches "hello" — both are leaks wearing a
        // feature's clothes. self::likeLiteral() escapes them, and the ESCAPE
        // clause names the escape character explicitly rather than relying on
        // the default, which differs between MySQL (backslash) and SQLite
        // (none at all). `!` is used instead of a backslash because a
        // backslash cannot be written as a string literal that means the same
        // thing in both dialects.
        $query->whereRaw(
            'body LIKE ? ESCAPE \''.self::LIKE_ESCAPE.'\'',
            ['%'.self::likeLiteral($request->string('q')->toString()).'%']
        );

        // Soft-deleted messages are excluded by the model's global scope. That
        // is intentional: a retracted message keeps its slot in the history as
        // "[deleted]", and search must not be the one place its text comes back.
        $page = $query->orderByDesc('id')->paginate(self::SEARCH_PAGE)->withQueryString();

        return response()->json([
            'results' => collect($page->items())
                ->map(fn (ChatConversationMessage $m) => $this->searchHit($m))
                ->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /**
     * Every conversation id this user may read, archived ones included.
     *
     * No `notArchived()` here, deliberately. Archive is a freeze, not a
     * deletion — readable, not postable — and the policy says exactly that
     * (`view()` has no archived check; `sendMessage()` does). Filtering
     * archived rooms out of search would make a member unable to find what they
     * themselves wrote last quarter, and would re-establish the sidebar listing
     * as an authorisation source, which is the bug fixed in 236d66a0.
     *
     * @return list<int>
     */
    private function readableConversationIds(User $user): array
    {
        return $this->scopeReadableBy(ChatConversation::query(), $user)
            // One eager load rather than a membership query per row: the policy
            // below reads `participants` for every conversation it is handed.
            ->with('participants')
            ->get(['id', 'type', 'is_private', 'department', 'archived_at'])
            // The policy is the authority; the query above was only a
            // pre-filter. Department scoping and private membership are decided
            // here, for every row, exactly as the sidebar decides them.
            ->filter(fn (ChatConversation $c) => $user->can('view', $c))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * One search result, carrying what the UI needs to jump straight to it.
     *
     * @return array<string, mixed>
     */
    private function searchHit(ChatConversationMessage $message): array
    {
        $conversation = $message->conversation;

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'conversation_name' => $conversation?->name ?? 'Conversation #'.$message->conversation_id,
            'conversation_archived' => $conversation?->isArchived() ?? false,
            'parent_id' => $message->parent_id,
            'author_name' => $message->authorName(),
            'body' => (string) $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
            // `m` is the message to scroll to and highlight once `c` has loaded.
            'url' => route('admin.chat.index', ['c' => $message->conversation_id, 'm' => $message->id]),
        ];
    }

    /**
     * The shape returned when the caller can read nothing the filters allow —
     * identical to a genuine no-match page, on purpose.
     *
     * @return array<string, mixed>
     */
    private function emptySearchPage(): array
    {
        return [
            'results' => [],
            'total' => 0,
            'per_page' => self::SEARCH_PAGE,
            'current_page' => 1,
            'last_page' => 1,
        ];
    }

    /**
     * Escape the LIKE metacharacters so a pattern matches the literal text the
     * user typed. Kept next to the ESCAPE clause in search() — the escape
     * character and the escaping have to agree, so they live together.
     */
    private static function likeLiteral(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $value,
        );
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
     * Unread badges for the whole sidebar, who is online, and the queue.
     *
     * This is the sidebar's only refresh path. Everything else the chat client
     * subscribes to is scoped to the ONE conversation that is open, so without
     * this endpoint a badge, a presence dot and a queued customer are all
     * frozen at whatever the server rendered on page load — and stay frozen for
     * as long as the tab is open. It is polled on the presence heartbeat tick
     * whether or not the websocket is up, because the websocket is not
     * configured at all on a default install (`BROADCAST_CONNECTION=log`).
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();
        $online = $this->presence->online();

        return response()->json([
            'unread' => $this->chat->unreadCounts($user),
            'online' => $online,
            // Availability travels with the roster it describes. Sent from the
            // same payload rather than a second endpoint because the sidebar
            // repaints the whole list from this response — a roster refreshed
            // without its states would repaint everyone as Available.
            'availability' => $this->availability->statesFor(
                array_map(static fn (array $person): int => (int) $person['id'], $online),
            ),
            'inbox' => $this->waitingInbox($user),
        ]);
    }

    /**
     * Customer conversations nobody has taken yet.
     *
     * The poll-side twin of the CustomerChatWaiting broadcast, so an operator
     * with no websocket still learns about a waiting customer within one
     * heartbeat instead of not at all. Only `waiting` rooms: an assigned
     * conversation already has its operator and is already in their sidebar.
     *
     * Empty for anyone without `chat.manage`, which is the same permission
     * ChatConversationPolicy::view() accepts for a customer inbox — this must
     * not become a way for a plain `chat.view` holder to enumerate customer
     * conversations from a corner of an endpoint that looks like a badge count.
     *
     * @return list<array<string, mixed>>
     */
    private function waitingInbox(User $user): array
    {
        if (! $user->hasPermission('chat.manage')) {
            return [];
        }

        return ChatConversation::query()
            ->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)
            ->where('status', ChatConversation::STATUS_WAITING)
            // customer.user is what displayName() reaches for on a signed-in
            // customer; without it this is one query per queued room.
            ->with('customer.user')
            ->orderByDesc('id')
            ->limit(self::INBOX_POLL_LIMIT)
            ->get()
            ->map(fn (ChatConversation $c) => [
                'id' => (int) $c->id,
                'name' => $c->displayName(),
                'status' => $c->status,
                'department' => $c->department,
                'url' => route('admin.chat.index', ['c' => $c->id]),
            ])
            ->all();
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

        $online = $this->presence->online();

        return response()->json([
            'online' => $online,
            // The roster and its badges travel together: a poll that refreshed
            // the names without the states would repaint everyone as Available.
            'availability' => $this->availability->statesFor(
                array_map(static fn (array $person): int => (int) $person['id'], $online),
            ),
            'heartbeat_seconds' => ChatPresence::HEARTBEAT_SECONDS,
        ]);
    }

    /**
     * "I am taking chats" / "I am not".
     *
     * Deliberately NOT part of the presence heartbeat. Presence is what the
     * browser knows (a tab is open) and expires by itself after 90 seconds;
     * this is what the person says, and it has to survive a closed laptop. Two
     * facts with two lifetimes, so two endpoints — folding the state into the
     * heartbeat payload would mean every heartbeat re-asserting a choice the
     * operator made hours ago, and a missed heartbeat quietly undoing it.
     *
     * Anyone who may use the chat may set their own state, and only their own:
     * the user comes from the session, never from the request body.
     */
    public function availability(UpdateChatAvailabilityRequest $request): JsonResponse
    {
        $row = $this->availability->set(
            $request->user(),
            $request->string('state')->toString(),
            $request->input('note'),
        );

        return response()->json([
            'state' => $row->state,
            'label' => $row->label(),
            'note' => $row->note,
            // What the widget would now decide, so the operator can see the
            // consequence of going Away: if they were the last one accepting,
            // the chat has just closed to customers.
            'accepting_operators' => $this->availability->acceptingCount(),
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
