<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Chat\UserPresence;
use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Who is online, for the sidebar dots.
 *
 * The Reverb presence channel already knows its own membership, but only the
 * connected clients can see it — the server never learns who joined. This
 * registry is the server-side copy, fed by a heartbeat from the client, so that
 * a page rendered over plain HTTP can show presence before any websocket has
 * connected, and so the polling fallback keeps working when Reverb is down.
 *
 * Cache-backed rather than a table: presence is worthless thirty seconds later,
 * and writing a row per heartbeat per user would be the busiest write in the
 * application for information nobody will ever query historically.
 *
 * Entries expire on their own. A browser that is closed, crashes, or loses its
 * network stops heartbeating and simply ages out — there is no "goodbye"
 * message to miss.
 */
class ChatPresence
{
    private const ROSTER_KEY = 'chat:presence:roster';

    /** Guards the read-modify-write of the roster below. */
    private const LOCK_KEY = 'chat:presence:roster:lock';

    /** How long the lock is held before it is assumed abandoned. */
    private const LOCK_TTL_SECONDS = 5;

    /** How long a caller waits for the lock before giving up on it. */
    private const LOCK_WAIT_SECONDS = 2;

    /** An entry older than this is treated as gone. Two missed heartbeats. */
    public const STALE_AFTER_SECONDS = 90;

    /** How often the client should call heartbeat(). */
    public const HEARTBEAT_SECONDS = 30;

    /**
     * Record that this user is here. Returns true if they were not already.
     */
    public function heartbeat(User $user): bool
    {
        $wasOnline = $this->mutate(function (array $roster) use ($user): array {
            $roster[$user->id] = [
                'id' => $user->id,
                'name' => $user->full_name,
                'at' => now()->getTimestamp(),
            ];

            return $roster;
        }, static fn (array $roster): bool => isset($roster[$user->id]));

        // Dispatched outside the critical section: a broadcast is a network
        // call, and holding the roster lock across one would serialise every
        // operator's heartbeat behind the slowest socket publish.
        if (! $wasOnline) {
            UserPresence::dispatch($user->id, $user->full_name, UserPresence::ONLINE);
        }

        return ! $wasOnline;
    }

    /**
     * An explicit goodbye — a closed tab or a left page.
     */
    public function leave(User $user): void
    {
        $wasOnline = $this->mutate(static function (array $roster) use ($user): array {
            unset($roster[$user->id]);

            return $roster;
        }, static fn (array $roster): bool => isset($roster[$user->id]));

        if ($wasOnline) {
            UserPresence::dispatch($user->id, $user->full_name, UserPresence::OFFLINE);
        }
    }

    /**
     * Read the roster, change it, and write it back — without losing a
     * concurrent writer's change.
     *
     * The roster is one cache key holding every online user, so a plain
     * get/modify/put loses an entry whenever two heartbeats overlap: both read
     * the same array, both write, and the second erases the first. At 30-second
     * heartbeats across a room of operators that happens routinely, and each
     * loss reads downstream as "went offline" followed by a spurious ONLINE
     * broadcast on the victim's next beat.
     *
     * The lock is held for the read AND the write, which is the only ordering
     * that closes the window. `$observe` runs inside it too, so what a caller
     * learns about the previous state is what the write was actually based on.
     *
     * Degrades rather than fails. A store with no lock support, or a lock we
     * could not get within LOCK_WAIT_SECONDS, falls through to the unlocked
     * path: a presence dot that flickers is worth less than the heartbeat
     * request that carried it, and dropping the write outright would make the
     * user disappear for certain rather than by chance.
     *
     * @param  callable(array<int, array{id: int, name: string, at: int}>): array<int, array{id: int, name: string, at: int}>  $mutator
     * @param  callable(array<int, array{id: int, name: string, at: int}>): bool  $observe
     */
    private function mutate(callable $mutator, callable $observe): bool
    {
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            return $this->mutateUnlocked($mutator, $observe);
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        try {
            return $lock->block(
                self::LOCK_WAIT_SECONDS,
                fn (): bool => $this->mutateUnlocked($mutator, $observe),
            );
        } catch (LockTimeoutException) {
            return $this->mutateUnlocked($mutator, $observe);
        }
    }

    /**
     * @param  callable(array<int, array{id: int, name: string, at: int}>): array<int, array{id: int, name: string, at: int}>  $mutator
     * @param  callable(array<int, array{id: int, name: string, at: int}>): bool  $observe
     */
    private function mutateUnlocked(callable $mutator, callable $observe): bool
    {
        $roster = $this->freshRoster();
        $observed = $observe($roster);

        $this->store($mutator($roster));

        return $observed;
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function online(): array
    {
        return array_values(array_map(
            static fn (array $entry) => ['id' => $entry['id'], 'name' => $entry['name']],
            $this->freshRoster(),
        ));
    }

    /**
     * @return array<int, int>
     */
    public function onlineIds(): array
    {
        return array_keys($this->freshRoster());
    }

    public function isOnline(int $userId): bool
    {
        return array_key_exists($userId, $this->freshRoster());
    }

    /**
     * The roster with expired entries dropped.
     *
     * @return array<int, array{id: int, name: string, at: int}>
     */
    private function freshRoster(): array
    {
        $roster = Cache::get(self::ROSTER_KEY, []);

        if (! is_array($roster)) {
            return [];
        }

        $cutoff = now()->getTimestamp() - self::STALE_AFTER_SECONDS;

        return array_filter(
            $roster,
            static fn ($entry) => is_array($entry) && ($entry['at'] ?? 0) >= $cutoff,
        );
    }

    /**
     * @param  array<int, array{id: int, name: string, at: int}>  $roster
     */
    private function store(array $roster): void
    {
        // The TTL is generous compared with STALE_AFTER_SECONDS: expiry is
        // decided per entry when reading, not by the cache dropping the lot.
        Cache::put(self::ROSTER_KEY, $roster, now()->addMinutes(30));
    }
}
