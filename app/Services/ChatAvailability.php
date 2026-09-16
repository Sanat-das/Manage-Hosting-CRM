<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatOperatorAvailability;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Who is taking customer chats.
 *
 * Two facts have to agree before a customer is told someone is there:
 *
 *   presence      is a browser connected? (ChatPresence, cache, 90s heartbeat)
 *   availability  has that person SAID they are taking chats? (this table)
 *
 * Either one alone is wrong in a way customers notice. Presence alone counts
 * the operator who is at their desk writing a report with the chat tab open.
 * Availability alone counts the operator who marked themselves Available on
 * Friday and went home.
 *
 * And both are then filtered by `chat.manage`, because an "operator" who cannot
 * open the customer inbox is not one. Without that filter every marketing user
 * with the chat panel open would count as support cover.
 */
class ChatAvailability
{
    /**
     * How long "is anyone accepting" is cached.
     *
     * The guest-facing availability endpoint asks this on every widget open, so
     * it must not be three queries each time. Short enough that an operator
     * going Away is reflected within a few seconds — anything longer and the
     * control feels broken to the person using it.
     */
    private const ROSTER_CACHE_SECONDS = 15;

    private const ROSTER_CACHE_KEY = 'chat:availability:accepting';

    public function __construct(private readonly ChatPresence $presence = new ChatPresence) {}

    /**
     * This user's declared state.
     *
     * No row means available. That equivalence is here and nowhere else: it is
     * what makes an install that has never touched the control behave exactly
     * as it did before the table existed.
     */
    public function stateFor(User $user): string
    {
        return $this->rowFor($user)?->state ?? ChatOperatorAvailability::STATE_AVAILABLE;
    }

    public function rowFor(User $user): ?ChatOperatorAvailability
    {
        return ChatOperatorAvailability::query()->where('user_id', $user->id)->first();
    }

    /**
     * Record a state, and the note that goes with it.
     *
     * `state_changed_at` only moves when the state actually changes, so editing
     * a note does not make a two-hour-old "Away" look like it just happened.
     *
     * An omitted or blank note clears the stored one rather than preserving it:
     * the control posts both fields together, so "no note" is a statement, and
     * a stale "back at 3" left hanging under a fresh Available is worse than
     * nothing.
     *
     * @throws \InvalidArgumentException on an unknown state
     */
    public function set(User $user, string $state, ?string $note = null): ChatOperatorAvailability
    {
        if (! in_array($state, ChatOperatorAvailability::STATES, true)) {
            throw new \InvalidArgumentException("[{$state}] is not an availability state.");
        }

        $existing = $this->rowFor($user);
        $changed = $existing === null || $existing->state !== $state;

        $attributes = [
            'state' => $state,
            'note' => $note === null || trim($note) === '' ? null : trim($note),
        ];

        if ($changed) {
            $attributes['state_changed_at'] = now();
        }

        $row = ChatOperatorAvailability::query()->updateOrCreate(['user_id' => $user->id], $attributes);

        // The roster answer this person is part of is now stale.
        $this->forget();

        return $row;
    }

    /**
     * States for a set of users, keyed by user id, with the absent rows filled
     * in as available.
     *
     * One query for the whole roster: the presence list renders a badge per
     * person and a query each would be a query per online operator per render.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{state: string, label: string, note: ?string}>
     */
    public function statesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = ChatOperatorAvailability::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $states = [];

        foreach ($userIds as $id) {
            $row = $rows->get($id);
            $state = $row?->state ?? ChatOperatorAvailability::STATE_AVAILABLE;

            $states[(int) $id] = [
                'state' => $state,
                'label' => ChatOperatorAvailability::LABELS[$state] ?? $state,
                'note' => $row?->note,
            ];
        }

        return $states;
    }

    /**
     * Is at least one operator online AND accepting?
     *
     * Cached, because the guest widget asks on every open and the answer is the
     * same for every visitor.
     */
    public function hasAcceptingOperator(): bool
    {
        return $this->acceptingCount() > 0;
    }

    public function acceptingCount(): int
    {
        return Cache::remember(
            self::ROSTER_CACHE_KEY,
            self::ROSTER_CACHE_SECONDS,
            fn (): int => count($this->acceptingOperatorIds())
        );
    }

    /**
     * The user ids that are online, hold chat.manage, and are not away or busy.
     *
     * @return array<int, int>
     */
    public function acceptingOperatorIds(): array
    {
        $onlineIds = $this->presence->onlineIds();

        if ($onlineIds === []) {
            return [];
        }

        $away = ChatOperatorAvailability::query()
            ->whereIn('user_id', $onlineIds)
            ->where('state', '!=', ChatOperatorAvailability::STATE_AVAILABLE)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $candidates = array_values(array_diff(
            array_map(static fn ($id) => (int) $id, $onlineIds),
            $away,
        ));

        if ($candidates === []) {
            return [];
        }

        // hasPermission() walks the role pivot, so the users are loaded once
        // and asked in memory rather than queried one at a time.
        return User::query()
            ->whereIn('id', $candidates)
            ->get()
            ->filter(static fn (User $user): bool => $user->hasPermission('chat.manage'))
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    public function forget(): void
    {
        Cache::forget(self::ROSTER_CACHE_KEY);
    }
}
