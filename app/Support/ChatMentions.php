<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatConversation;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Parse @mentions from a chat message body.
 *
 * Two forms: `@channel` fans out to every participant except the author, and
 * `@Name` targets a single person whose `full_name` appears after the `@`.
 *
 * Names contain spaces — "@John Doe" is a single mention, not "@John" plus
 * litter. The parser therefore matches the longest participant name starting at
 * each `@`, not a word token. That also keeps an email address from becoming a
 * mention: `a@b.com` has no whitespace before the `@` and never matches a
 * participant name.
 *
 * Fan-out is bounded: a message carrying hundreds of distinct mentions would
 * otherwise create hundreds of notification rows and broadcasts from a single
 * request. The bound is deliberately small — a real message does not address
 * twenty different people by name.
 */
final class ChatMentions
{
    /** A single message may not notify more than this many distinct users. */
    public const MAX_MENTIONS = 20;

    /** A single message may not trigger @channel in a room larger than this. */
    public const MAX_CHANNEL_FANOUT = 50;

    /**
     * Who is mentioned in $body, filtered to people who can actually see the
     * conversation and capped to MAX_MENTIONS.
     *
     * @return Collection<int, User> distinct users, never including $author
     */
    public static function mentionedUsers(string $body, ChatConversation $conversation, ?User $author): Collection
    {
        if (trim($body) === '') {
            return collect();
        }

        $isChannel = self::containsChannelMention($body);

        // Fast path: body contains neither @channel nor a plausible @name.
        if (! $isChannel && ! str_contains($body, '@')) {
            return collect();
        }

        // Load the conversation's participants with their users once, rather than
        // one query per candidate name.
        $participants = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->map(fn (ChatParticipant $p) => $p->user)
            ->filter()
            ->values();

        if ($participants->isEmpty()) {
            return collect();
        }

        $mentioned = collect();

        // Two different bounds, and the wider one applies when @channel is what
        // did the addressing. Capping unconditionally at MAX_MENTIONS made
        // MAX_CHANNEL_FANOUT unreachable: the `take(50)` below was immediately
        // truncated back to 20 on the way out, so the documented 50 never
        // happened and nothing asserted that it should.
        $cap = $isChannel ? self::MAX_CHANNEL_FANOUT : self::MAX_MENTIONS;

        // @channel — every participant except the author, bounded.
        if ($isChannel) {
            $channelUsers = $participants
                ->filter(fn (User $u) => $author === null || $u->id !== $author->id)
                ->filter(fn (User $u) => $u->can('view', $conversation))
                ->take(self::MAX_CHANNEL_FANOUT)
                ->values();

            foreach ($channelUsers as $user) {
                $mentioned->push($user);
            }
        }

        // Named mentions — longest participant name wins at each @.
        // Sort by name length descending so "John Doe" is tried before "John".
        $sorted = $participants->sortByDesc(fn (User $u) => mb_strlen($u->full_name))->values();
        $bodyLower = mb_strtolower($body);
        $authorId = $author?->id;

        // Scan the body for @ occurrences. At each one, try every participant
        // name; the first (longest) that matches the slice is a mention.
        $pos = 0;
        $len = mb_strlen($body);

        while ($pos < $len) {
            $at = mb_strpos($bodyLower, '@', $pos);

            if ($at === false) {
                break;
            }

            // Email guard: a mention must start at the beginning of the string
            // or be preceded by whitespace / punctuation that a person would
            // actually type before addressing someone. An @ inside an email
            // address is preceded by a word character and is not a mention.
            if ($at > 0) {
                $prev = mb_substr($body, $at - 1, 1);

                if (preg_match('/[\w]/u', $prev)) {
                    $pos = $at + 1;

                    continue;
                }
            }

            // An isolated @ or @@ with nothing useful after it.
            if ($at + 1 >= $len) {
                $pos = $at + 1;

                continue;
            }

            $matchedUser = null;

            foreach ($sorted as $candidate) {
                $name = $candidate->full_name;

                if ($name === '' || $name === null) {
                    continue;
                }

                $nameLen = mb_strlen($name);
                $slice = mb_substr($body, $at + 1, $nameLen);

                if (mb_strtolower($slice) !== mb_strtolower($name)) {
                    continue;
                }

                // The name must be bounded: the character after it cannot be a
                // word/dot/hyphen character, or "@John" inside "@Johnny" would
                // fire for both.
                $after = $at + 1 + $nameLen;

                if ($after < $len) {
                    $next = mb_substr($body, $after, 1);

                    if (preg_match('/[\w.\-]/u', $next)) {
                        continue;
                    }
                }

                $matchedUser = $candidate;
                break; // longest first, so first match wins
            }

            if ($matchedUser !== null) {
                $already = $mentioned->contains(fn (User $u) => $u->id === $matchedUser->id);
                $isSelf = $authorId !== null && $matchedUser->id === $authorId;

                if (! $already && ! $isSelf && $matchedUser->can('view', $conversation)) {
                    $mentioned->push($matchedUser);

                    if ($mentioned->count() >= $cap) {
                        break;
                    }
                }

                $pos = $at + 1 + mb_strlen($matchedUser->full_name);

                continue;
            }

            // No participant name matched — advance past the @ so a body full of
            // @nonexistent tokens is scanned in linear time, not quadratic.
            $pos = $at + 1;
        }

        return $mentioned->take($cap)->values();
    }

    public static function containsChannelMention(string $body): bool
    {
        // @channel must be a whole token, not a prefix of "@channel2" and not
        // inside an email address.
        return (bool) preg_match('/(?<![\w.\-])@channel\b/i', $body);
    }
}
