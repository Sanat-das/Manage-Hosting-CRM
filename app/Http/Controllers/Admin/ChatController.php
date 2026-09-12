<?php

namespace App\Http\Controllers\Admin;

use App\Events\Chat\TypingIndicator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreChatChannelRequest;
use App\Http\Requests\Chat\StoreChatMessageRequest;
use App\Http\Requests\Chat\ToggleChatReactionRequest;
use App\Http\Requests\Chat\TypingHeartbeatRequest;
use App\Http\Requests\Chat\UpdateChatChannelRequest;
use App\Http\Requests\Chat\UpdateChatMessageRequest;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\ChatPresence;
use App\Services\ChatService;
use App\Support\ChatMessagePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

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

    public function index(Request $request): View
    {
        $status = $request->query('status');

        $search = trim((string) $request->query('search'));

        $sessions = ChatSession::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%");
            }))
            ->gridSort([
                'id' => 'id',
                'name' => 'name',
                'department' => 'department',
                'status' => 'status',
                'started_at' => 'started_at',
            ])
            ->orderByDesc('started_at')
            ->paginate(20)
            ->withQueryString();

        $statuses = ['waiting', 'active', 'closed'];
        $stats = ChatSession::selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');

        return view('admin.chat.index', compact('sessions', 'status', 'statuses', 'stats', 'search'));
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
            ->with(['user', 'attachments', 'entityLinks'])
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

        $message->load(['user', 'attachments', 'entityLinks']);

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

        $message->load(['user', 'attachments', 'entityLinks']);

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
            ->with(['user', 'attachments', 'entityLinks'])
            ->get();

        return response()->json([
            'parent' => ChatMessagePayload::for($parent->load(['user', 'attachments', 'entityLinks'])),
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
