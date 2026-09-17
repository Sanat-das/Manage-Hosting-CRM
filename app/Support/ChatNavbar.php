<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Throwable;

/**
 * Feeds the top-navbar messages dropdown from Live Chat.
 *
 * The package default (NavbarData::messages) reads an `adminlte_messages`
 * mailbox table that this install never scaffolded, so it always fell back
 * to the demo names (Brad Diesel, John Pierce, ...). Live Chat is the
 * mailbox here: unread rows come from ChatService::unreadCounts() — the
 * same source the chat sidebar badges use — plus the waiting customer
 * queue for operators, which has no participant row yet and therefore
 * never appears in an unread count.
 *
 * Every method is defensive: the navbar renders on every page, so a chat
 * failure must degrade to an empty dropdown, never a 500.
 */
final class ChatNavbar
{
    /** Rows shown in the dropdown. */
    public const LIMIT = 5;

    /**
     * Monogram background/text pairs (Bootstrap subtle utilities, so they
     * follow light/dark mode). Indexed by conversation id, which keeps one
     * room's colour stable across renders.
     */
    public const COLORS = ['primary', 'success', 'danger', 'warning', 'info', 'secondary'];

    /**
     * Newest-first conversation previews with unread activity.
     *
     * Shape matches what navbar-messages.blade.php reads:
     * id, name, initials, text, time, url, unread, waiting.
     *
     * @return list<array<string, mixed>>
     */
    public static function messages(?User $user, int $limit = self::LIMIT): array
    {
        if ($user === null || ! $user->hasPermission('chat.view')) {
            return [];
        }

        try {
            $items = [];

            // Waiting customers first: they need an operator, not a reader.
            if ($user->hasPermission('chat.manage')) {
                foreach (self::waiting($user, $limit) as $row) {
                    $items[] = $row;
                }
            }

            $remaining = $limit - count($items);

            if ($remaining > 0) {
                foreach (self::unread($user, $remaining) as $row) {
                    // A waiting room already listed above must not repeat.
                    foreach ($items as $existing) {
                        if ($existing['id'] === $row['id']) {
                            continue 2;
                        }
                    }

                    $items[] = $row;
                }
            }

            return array_slice($items, 0, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Badge number: total unread messages plus waiting customers.
     */
    public static function messageCount(?User $user): int
    {
        if ($user === null || ! $user->hasPermission('chat.view')) {
            return 0;
        }

        try {
            $total = array_sum(app(ChatService::class)->unreadCounts($user));

            if ($user->hasPermission('chat.manage')) {
                $total += ChatConversation::query()
                    ->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)
                    ->where('status', ChatConversation::STATUS_WAITING)
                    ->count();
            }

            return max(0, (int) $total);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Destination for "See all messages" — the Live Chat workspace.
     */
    public static function indexUrl(): string
    {
        return Route::has('admin.chat.index') ? route('admin.chat.index') : '#';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function waiting(User $user, int $limit): array
    {
        $rooms = ChatConversation::query()
            ->where('type', ChatConversation::TYPE_CUSTOMER_INBOX)
            ->where('status', ChatConversation::STATUS_WAITING)
            ->with('customer.user')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(fn (ChatConversation $c) => $user->can('view', $c))
            ->values();

        return $rooms->map(function (ChatConversation $c) {
            $name = $c->displayName();
            $opening = self::latestPreview($c);

            // The operator triages on words, not status: the pill already
            // says "waiting", so this line carries the opening message plus
            // the queue context — never a second copy of the status.
            $text = $opening !== null
                ? $opening['preview'].($c->department ? ' · '.$c->department : '')
                : ($c->department ?: ($c->guest_email ?: 'Customer chat'));

            return [
                'id' => (int) $c->id,
                'name' => $name,
                'initials' => self::initials($name),
                // Waiting rooms are always amber: the pill already says
                // "waiting", so the monogram echoes it instead of hashing.
                'color' => 'warning',
                'text' => $text,
                'time' => $c->created_at?->diffForHumans() ?? '',
                'url' => route('admin.chat.index', ['c' => $c->id]),
                'unread' => 0,
                'waiting' => true,
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function unread(User $user, int $limit): array
    {
        $counts = app(ChatService::class)->unreadCounts($user);

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        $conversations = ChatConversation::query()
            ->whereIn('id', array_keys($counts))
            ->with(['participants.user', 'customer.user', 'assignedOperator'])
            ->get()
            ->filter(fn (ChatConversation $c) => $user->can('view', $c))
            ->sortBy(fn (ChatConversation $c) => array_search((int) $c->id, array_keys($counts), true))
            ->take($limit)
            ->values();

        $rows = [];

        foreach ($conversations as $conversation) {
            $found = self::latestPreview($conversation);
            $preview = $found['preview'] ?? 'New activity';

            $name = $conversation->labelFor($user);

            $rows[] = [
                'id' => (int) $conversation->id,
                'name' => $name,
                'initials' => self::initials($name),
                'color' => self::color((int) $conversation->id),
                'text' => $preview,
                'time' => ($found['time'] ?? '') !== '' ? $found['time'] : ($conversation->updated_at?->diffForHumans() ?? ''),
                'url' => route('admin.chat.index', ['c' => $conversation->id]),
                'unread' => (int) ($counts[$conversation->id] ?? 0),
                'waiting' => false,
            ];
        }

        return $rows;
    }

    /**
     * Newest message preview plus its timestamp, or null when the room has
     * no readable message. One helper for both row types so waiting and
     * unread previews can never drift apart in truncation or escaping.
     *
     * @return ?array{preview: string, time: string}
     */
    private static function latestPreview(ChatConversation $conversation): ?array
    {
        $latest = $conversation->messages()->orderByDesc('id')->first();

        if ($latest === null) {
            return null;
        }

        $body = Str::limit(trim(preg_replace('/\s+/', ' ', $latest->visibleBody()) ?? ''), 60);

        if ($body === '') {
            return null;
        }

        return [
            'preview' => $body,
            'time' => $latest->created_at?->diffForHumans() ?? '',
        ];
    }

    /**
     * Two-letter monogram for the CSS avatar circle. Staff have no avatar
     * column and the package's stock photos are not published, so rows
     * render initials instead of a broken <img>.
     */
    private static function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = mb_substr((string) ($words[0] ?? '?'), 0, 1);
        $second = isset($words[1]) ? mb_substr($words[1], 0, 1) : '';

        $monogram = mb_strtoupper($first.$second);

        return $monogram !== '' ? $monogram : '?';
    }

    /**
     * Stable palette slot for a conversation id.
     */
    private static function color(int $conversationId): string
    {
        return self::COLORS[$conversationId % count(self::COLORS)];
    }
}
