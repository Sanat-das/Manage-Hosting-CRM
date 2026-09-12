<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The write side of the Slack-like chat.
 *
 * Controllers, console commands and seeders all come through here, so the
 * invariants live in one place: a conversation always has at least its creator
 * as a participant, a slug is always unique, a department string is always one
 * that exists, and a thread reply always belongs to the same conversation as
 * the message it answers.
 *
 * Authorisation is NOT done here — that is ChatConversationPolicy's job, called
 * by the controller. A service that silently refused would be impossible to
 * reuse from a seeder or a console command.
 */
class ChatService
{
    public const MAX_BODY_LENGTH = 4000;

    /** A group DM beyond this is a channel that has not admitted it yet. */
    public const MAX_GROUP_DM_PARTICIPANTS = 50;

    /**
     * Create a channel and put its creator in it as an admin.
     *
     * @throws InvalidArgumentException on an unusable name or unknown department
     */
    public function createChannel(
        string $name,
        User $creator,
        bool $isPrivate = false,
        ?string $department = null,
        ?string $topic = null,
        ?string $purpose = null,
    ): ChatConversation {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('A channel needs a name.');
        }

        $department = $this->normaliseDepartment($department);

        return DB::transaction(function () use ($name, $creator, $isPrivate, $department, $topic, $purpose) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_CHANNEL,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'is_private' => $isPrivate,
                'department' => $department,
                'topic' => $topic,
                'purpose' => $purpose,
                'created_by' => $creator->id,
            ]);

            $this->addMember($conversation, $creator, ChatParticipant::ROLE_ADMIN);

            return $conversation;
        });
    }

    /**
     * Find or create the 1:1 conversation between two users.
     *
     * Idempotent on purpose: opening a DM twice from two different places must
     * not produce two histories of the same conversation.
     */
    public function findOrCreateDirectMessage(User $a, User $b): ChatConversation
    {
        if ($a->id === $b->id) {
            throw new InvalidArgumentException('A direct message needs two different people.');
        }

        $existing = $this->existingDirectMessage($a, $b);

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($a, $b) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_DM,
                'created_by' => $a->id,
            ]);

            $this->addMember($conversation, $a);
            $this->addMember($conversation, $b);

            return $conversation;
        });
    }

    /**
     * A named or unnamed conversation between three or more staff.
     *
     * @param  Collection<int, User>|array<int, User>  $members
     */
    public function createGroupDirectMessage(iterable $members, User $creator, ?string $name = null): ChatConversation
    {
        $people = collect($members)->push($creator)->unique('id')->values();

        if ($people->count() < 3) {
            throw new InvalidArgumentException('A group message needs at least three people; use a direct message for two.');
        }

        if ($people->count() > self::MAX_GROUP_DM_PARTICIPANTS) {
            throw new InvalidArgumentException(
                'A group message is capped at '.self::MAX_GROUP_DM_PARTICIPANTS.' people; open a channel instead.'
            );
        }

        return DB::transaction(function () use ($people, $creator, $name) {
            $conversation = ChatConversation::create([
                'type' => ChatConversation::TYPE_GROUP_DM,
                'name' => $name !== null && trim($name) !== '' ? trim($name) : null,
                'created_by' => $creator->id,
            ]);

            foreach ($people as $person) {
                $this->addMember(
                    $conversation,
                    $person,
                    $person->id === $creator->id ? ChatParticipant::ROLE_ADMIN : ChatParticipant::ROLE_MEMBER,
                );
            }

            return $conversation;
        });
    }

    /**
     * Add someone to a conversation, or return the row they already have.
     */
    public function addMember(
        ChatConversation $conversation,
        User $user,
        string $role = ChatParticipant::ROLE_MEMBER,
    ): ChatParticipant {
        if ($conversation->type === ChatConversation::TYPE_GROUP_DM
            && $conversation->participants()->count() >= self::MAX_GROUP_DM_PARTICIPANTS) {
            throw new RuntimeException('This group message is full.');
        }

        $participant = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant !== null) {
            return $participant;
        }

        return ChatParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }

    /**
     * Remove someone. Their messages stay: a conversation that loses its
     * history when a person leaves is not a record of anything.
     */
    public function removeMember(ChatConversation $conversation, User $user): void
    {
        ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * Freeze a conversation. Nothing is deleted — archived rooms stay readable
     * and searchable, they just stop accepting messages.
     */
    public function archive(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill(['archived_at' => now()])->save();

        return $conversation;
    }

    public function unarchive(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill(['archived_at' => null])->save();

        return $conversation;
    }

    /**
     * Post a message, optionally as a reply within a thread.
     *
     * `$guestToken` is how a customer with no account is identified; it is
     * written here from an already-validated session token and is never
     * mass-assignable.
     */
    public function sendMessage(
        ChatConversation $conversation,
        ?User $user,
        string $body,
        ?int $parentId = null,
        ?string $guestToken = null,
    ): ChatConversationMessage {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A message cannot be empty.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('A message cannot exceed '.self::MAX_BODY_LENGTH.' characters.');
        }

        if ($conversation->isArchived()) {
            throw new RuntimeException('This conversation is archived.');
        }

        if ($user === null && $guestToken === null) {
            throw new InvalidArgumentException('A message needs an author or a guest token.');
        }

        $parentId = $this->resolveThreadParent($conversation, $parentId);

        return DB::transaction(function () use ($conversation, $user, $body, $parentId, $guestToken) {
            $message = new ChatConversationMessage([
                'conversation_id' => $conversation->id,
                'user_id' => $user?->id,
                'parent_id' => $parentId,
                'body' => $body,
            ]);

            if ($guestToken !== null) {
                $message->guest_token = $guestToken;
            }

            $message->save();

            // The author has by definition read their own message; without this
            // their own send would come back at them as an unread.
            if ($user !== null) {
                ChatParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('user_id', $user->id)
                    ->update(['last_read_message_id' => $message->id]);
            }

            return $message;
        });
    }

    public function editMessage(ChatConversationMessage $message, string $body): ChatConversationMessage
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('A message cannot be empty.');
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('A message cannot exceed '.self::MAX_BODY_LENGTH.' characters.');
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        return $message;
    }

    /**
     * Soft delete. The row survives so its thread keeps its anchor and the UI
     * can render "[deleted]" rather than losing the replies.
     */
    public function deleteMessage(ChatConversationMessage $message): void
    {
        $message->delete();
    }

    /**
     * Resolve the parent of a thread reply.
     *
     * Threads are one level deep: replying to a reply attaches to the same root,
     * which is what keeps a thread a thread rather than a tree nobody can render.
     */
    private function resolveThreadParent(ChatConversation $conversation, ?int $parentId): ?int
    {
        if ($parentId === null) {
            return null;
        }

        $parent = ChatConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->find($parentId);

        if ($parent === null) {
            throw new InvalidArgumentException('The message being replied to is not in this conversation.');
        }

        return $parent->parent_id ?? $parent->id;
    }

    private function existingDirectMessage(User $a, User $b): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('type', ChatConversation::TYPE_DM)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $a->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $b->id))
            ->withCount('participants')
            ->get()
            ->firstWhere('participants_count', 2);
    }

    /**
     * Channel slugs are unique across the table, so a clash gets a numeric
     * suffix rather than a 500 from the unique index.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'channel';
        $slug = $base;
        $suffix = 2;

        while (ChatConversation::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * A department is stored as a plain string, so it is checked here against
     * the departments that actually exist rather than by a foreign key to a
     * slug users can rename.
     */
    private function normaliseDepartment(?string $department): ?string
    {
        if ($department === null || trim($department) === '') {
            return null;
        }

        $department = trim($department);

        if (! array_key_exists($department, TicketService::departments())) {
            throw new InvalidArgumentException("Unknown department [{$department}].");
        }

        return $department;
    }
}
