<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\StoreCannedReplyRequest;
use App\Models\ChatCannedReply;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saved replies.
 *
 * Two audiences, one table. The CRUD screens are ordinary admin pages; `pick()`
 * is the JSON the composer's `/shortcut` picker reads.
 *
 * Authorisation is not a single `permission:` line, because it depends on the
 * row and not only on the caller:
 *
 *   read           anyone with chat.view sees the shared library plus their own
 *   write shared   chat.manage
 *   write own      the owner, and nobody else — not even chat.manage, which is
 *                  a permission over the chat and not over another person's
 *                  private notes
 *
 * The route carries `permission:chat.view` for the floor, and every write comes
 * through authoriseWrite() below for the rest. Expressing it as one route gate
 * would mean either locking every operator out of their own snippets or letting
 * any of them rewrite the shared library.
 */
class ChatCannedReplyController extends Controller
{
    /** Rows per page on the management screen. */
    private const PER_PAGE = 20;

    /** Replies returned to the composer picker in one go. */
    private const PICK_LIMIT = 50;

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $scope = (string) $request->query('scope', '');

        $replies = ChatCannedReply::query()
            ->visibleTo($user)
            ->with(['owner', 'author'])
            ->when($search !== '', function ($query) use ($search): void {
                // Title, shortcut and body: an operator looking for a snippet
                // remembers one of the three and rarely the same one twice.
                $like = '%'.$search.'%';

                $query->where(function ($q) use ($like): void {
                    $q->where('title', 'like', $like)
                        ->orWhere('shortcut', 'like', $like)
                        ->orWhere('body', 'like', $like);
                });
            })
            ->when($scope === 'shared', fn ($query) => $query->whereNull('user_id'))
            ->when($scope === 'personal', fn ($query) => $query->where('user_id', $user->id))
            ->gridSort([
                'title' => 'title',
                'shortcut' => 'shortcut',
                'department' => 'department',
                'uses' => 'uses',
                'updated_at' => 'updated_at',
            ])
            ->orderBy('title')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.chat.canned_replies.index', [
            'replies' => $replies,
            'search' => $search,
            'scope' => $scope,
            'canManage' => $user->hasPermission('chat.manage'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.chat.canned_replies.create', [
            'departments' => TicketService::departments(),
            'canManage' => $request->user()->hasPermission('chat.manage'),
        ]);
    }

    public function store(StoreCannedReplyRequest $request): RedirectResponse
    {
        $user = $request->user();
        $shared = $request->input('scope') === 'shared';

        $this->authoriseWrite($user, $shared);

        ChatCannedReply::create([
            'title' => $request->string('title')->toString(),
            'shortcut' => $this->shortcut($request->input('shortcut')),
            'body' => $request->string('body')->toString(),
            'department' => $request->input('department') ?: null,
            'user_id' => $shared ? null : $user->id,
            'created_by' => $user->id,
        ]);

        return redirect()
            ->route('admin.chat.canned-replies.index')
            ->with('success', 'Saved reply created.');
    }

    public function edit(Request $request, ChatCannedReply $cannedReply): View
    {
        $this->authoriseRead($request->user(), $cannedReply);

        return view('admin.chat.canned_replies.edit', [
            'reply' => $cannedReply,
            'departments' => TicketService::departments(),
            'canManage' => $request->user()->hasPermission('chat.manage'),
        ]);
    }

    public function update(StoreCannedReplyRequest $request, ChatCannedReply $cannedReply): RedirectResponse
    {
        $user = $request->user();
        $shared = $request->input('scope') === 'shared';

        // Both sides of the move are checked: taking a reply INTO the shared
        // library and taking one OUT of it are both edits to the shared
        // library, and either without chat.manage would be a way around it.
        $this->authoriseWrite($user, $shared || $cannedReply->isShared(), $cannedReply);

        $cannedReply->update([
            'title' => $request->string('title')->toString(),
            'shortcut' => $this->shortcut($request->input('shortcut')),
            'body' => $request->string('body')->toString(),
            'department' => $request->input('department') ?: null,
            'user_id' => $shared ? null : ($cannedReply->user_id ?? $user->id),
        ]);

        return redirect()
            ->route('admin.chat.canned-replies.index')
            ->with('success', 'Saved reply updated.');
    }

    public function destroy(Request $request, ChatCannedReply $cannedReply): RedirectResponse
    {
        $this->authoriseWrite($request->user(), $cannedReply->isShared(), $cannedReply);

        $cannedReply->delete();

        return redirect()
            ->route('admin.chat.canned-replies.index')
            ->with('success', 'Saved reply deleted.');
    }

    /**
     * The picker's list.
     *
     * Scoped to what this user may see by the same `visibleTo` scope the
     * management screen uses, so a personal reply cannot be fetched by anyone
     * else by guessing an id — there is no by-id endpoint here at all, only
     * this list, and the body travels with it so inserting a reply costs no
     * second request.
     *
     * Personal replies are ordered FIRST, which is what makes a personal
     * `/refund` usefully override the shared one: the picker resolves a typed
     * shortcut to the first match.
     */
    public function pick(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = trim((string) $request->query('q', ''));

        $replies = ChatCannedReply::query()
            ->visibleTo($user)
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.$query.'%';

                $builder->where(function ($q) use ($like): void {
                    $q->where('title', 'like', $like)
                        ->orWhere('shortcut', 'like', $like)
                        ->orWhere('body', 'like', $like);
                });
            })
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('title')
            ->limit(self::PICK_LIMIT)
            ->get();

        return response()->json([
            'replies' => $replies->map(static fn (ChatCannedReply $reply): array => [
                'id' => (int) $reply->id,
                'title' => (string) $reply->title,
                'shortcut' => $reply->shortcut,
                'body' => (string) $reply->body,
                'department' => $reply->department,
                'shared' => $reply->isShared(),
            ])->all(),

            // Whether this operator has ANY visible reply, regardless of the
            // search. Without it the picker cannot tell "your search matched
            // nothing" from "there is nothing to search", and it showed "No
            // saved replies match" on an install that had never created one —
            // blaming a filter for an empty library and offering nowhere to go.
            //
            // A second count() rather than inferring it from `replies`: with a
            // query typed, an empty result set says nothing about whether the
            // library is empty.
            'any' => $query === ''
                ? $replies->isNotEmpty()
                : ChatCannedReply::query()->visibleTo($user)->exists(),
        ]);
    }

    /**
     * Count a use.
     *
     * A separate tiny endpoint rather than a flag on the message write, because
     * the message write is the hot path and this is bookkeeping: the number
     * exists to tell an admin which snippets are worth keeping. It is fired and
     * forgotten by the client, and a lost increment costs nothing.
     */
    public function used(Request $request, ChatCannedReply $cannedReply): JsonResponse
    {
        $this->authoriseRead($request->user(), $cannedReply);

        $cannedReply->increment('uses');

        return response()->json(['uses' => (int) $cannedReply->fresh()->uses]);
    }

    /**
     * A blank shortcut is stored as NULL, not as ''.
     *
     * Empty strings would collide with each other under the uniqueness rule
     * while meaning "no shortcut", so every reply without one would be a clash
     * with every other.
     */
    private function shortcut(mixed $value): ?string
    {
        $shortcut = trim((string) $value);

        return $shortcut === '' ? null : ltrim($shortcut, '/');
    }

    private function authoriseRead(User $user, ChatCannedReply $reply): void
    {
        abort_unless($reply->isShared() || $reply->belongsToUser($user), 403);
    }

    /**
     * @param  bool  $touchesShared  whether this write affects the shared library
     */
    private function authoriseWrite(User $user, bool $touchesShared, ?ChatCannedReply $reply = null): void
    {
        if ($touchesShared) {
            abort_unless($user->hasPermission('chat.manage'), 403);

            return;
        }

        // A personal reply: only its owner, and a new one is owned by whoever
        // is creating it.
        if ($reply !== null) {
            abort_unless($reply->belongsToUser($user), 403);
        }
    }
}
