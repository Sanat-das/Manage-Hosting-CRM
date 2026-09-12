<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Chat\UserPresence;
use App\Models\User;
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

    /** An entry older than this is treated as gone. Two missed heartbeats. */
    public const STALE_AFTER_SECONDS = 90;

    /** How often the client should call heartbeat(). */
    public const HEARTBEAT_SECONDS = 30;

    /**
     * Record that this user is here. Returns true if they were not already.
     */
    public function heartbeat(User $user): bool
    {
        $roster = $this->freshRoster();
        $wasOnline = isset($roster[$user->id]);

        $roster[$user->id] = [
            'id' => $user->id,
            'name' => $user->full_name,
            'at' => now()->getTimestamp(),
        ];

        $this->store($roster);

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
        $roster = $this->freshRoster();

        if (! isset($roster[$user->id])) {
            return;
        }

        unset($roster[$user->id]);
        $this->store($roster);

        UserPresence::dispatch($user->id, $user->full_name, UserPresence::OFFLINE);
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
