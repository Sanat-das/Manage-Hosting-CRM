<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\Chat\ChatMessageDeleted;
use App\Events\Chat\ChatMessageEdited;
use App\Events\Chat\CustomerChatWaiting;
use App\Events\Chat\NewChatMessage;
use App\Events\Chat\ReactionToggled;
use App\Models\AuditLog;
use App\Models\ChatConversation;
use App\Models\ChatConversationMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatParticipant;
use App\Models\ChatReaction;
use App\Models\Customer;
use App\Models\MessageEntityLink;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChatAssignmentNotification;
use App\Notifications\ChatMentionNotification;
use App\Notifications\ChatReplyNotification;
use App\Support\ChatMentions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

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

    /** `audit_log.entity_type` for a conversation-level action. */
    private const AUDIT_ENTITY_CONVERSATION = 'chat_conversation';

    /** `audit_log.entity_type` for a message-level action. */
    private const AUDIT_ENTITY_MESSAGE = 'chat_message';

    /**
     * The notification gate is injected so a test can swap it, but it defaults
     * to a plain instance: every existing `new ChatService` call site in the
     * app and the test suite keeps working untouched.
     */
    public function __construct(
        private readonly NotificationPreferenceService $preferences = new NotificationPreferenceService,
        private readonly ChatTranscriptEmailService $transcripts = new ChatTranscriptEmailService,
    ) {}

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

            // The creator's own seat comes with the room; it is covered by the
            // channel_created row below rather than a second member_added one.
            $this->addMember($conversation, $creator, ChatParticipant::ROLE_ADMIN, recordAudit: false);

            $this->audit('chat.channel_created', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
                'name' => $conversation->name,
                'slug' => $conversation->slug,
                'type' => $conversation->type,
                'is_private' => (bool) $conversation->is_private,
                'department' => $conversation->department,
            ], $creator);

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

            // The two seats ARE the DM — see addMember()'s $recordAudit note.
            $this->addMember($conversation, $a, recordAudit: false);
            $this->addMember($conversation, $b, recordAudit: false);

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
                    recordAudit: false,
                );
            }

            return $conversation;
        });
    }

    /**
     * Add someone to a conversation, or return the row they already have.
     *
     * `$recordAudit` is false for the seats that come with a room being created
     * (the channel creator, both halves of a DM, the operator taking a customer
     * conversation): those are already described by the audit row for the
     * action that caused them, and a second "member added" row alongside says
     * nothing new. An idempotent re-add writes nothing either — an audit trail
     * records changes, not requests.
     */
    public function addMember(
        ChatConversation $conversation,
        User $user,
        string $role = ChatParticipant::ROLE_MEMBER,
        bool $recordAudit = true,
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

        $participant = ChatParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
        ]);

        if ($recordAudit) {
            $this->audit('chat.member_added', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
                'conversation_type' => $conversation->type,
                'name' => $conversation->name,
                'member_user_id' => $user->id,
                'role' => $role,
            ]);
        }

        return $participant;
    }

    /**
     * Remove someone. Their messages stay: a conversation that loses its
     * history when a person leaves is not a record of anything.
     */
    public function removeMember(ChatConversation $conversation, User $user): void
    {
        $removed = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->delete();

        // Nothing removed means they were not in the room — no change, no row.
        if ($removed > 0) {
            $this->audit('chat.member_removed', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
                'conversation_type' => $conversation->type,
                'name' => $conversation->name,
                'member_user_id' => $user->id,
            ]);
        }
    }

    /**
     * Freeze a conversation. Nothing is deleted — archived rooms stay readable
     * and searchable, they just stop accepting messages.
     */
    public function archive(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill(['archived_at' => now()])->save();

        $this->audit('chat.channel_archived', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'name' => $conversation->name,
            'slug' => $conversation->slug,
            'archived_at' => $conversation->archived_at?->toDateTimeString(),
        ]);

        return $conversation;
    }

    public function unarchive(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill(['archived_at' => null])->save();

        // Archiving was recorded and thawing was not, so the trail showed rooms
        // freezing and never reopening — and a room that is archived in the log
        // but writable in the app is the kind of discrepancy an audit is
        // supposed to settle, not create.
        $this->audit('chat.channel_unarchived', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'name' => $conversation->name,
            'slug' => $conversation->slug,
        ]);

        return $conversation;
    }

    /**
     * Permanently delete a channel and everything in it: messages (including
     * soft-deleted ones), reactions, entity links, attachments with their
     * files, and memberships.
     *
     * Channels only. A DM is defined by who is in it and a customer inbox is
     * support history with transcript obligations — neither is deletable
     * through this path, enforced here as well as in the controller so a
     * future caller cannot bypass the shape check by calling the service.
     *
     * @throws InvalidArgumentException on a non-channel conversation
     */
    public function deleteChannel(ChatConversation $conversation): void
    {
        if ($conversation->type !== ChatConversation::TYPE_CHANNEL) {
            throw new InvalidArgumentException('Only channels can be deleted.');
        }

        $messageIds = $conversation->messages()->withTrashed()->pluck('id');
        $participantCount = $conversation->participants()->count();

        $attachments = ChatMessageAttachment::query()->whereIn('message_id', $messageIds)->get();

        // Files first and best-effort: a missing file must not abort the
        // delete, and deleting the rows first would strand files no row points
        // at. Either order can leave an orphan on a crash midway; files-first
        // leaves the retryable one (the rows still know their paths).
        foreach ($attachments as $attachment) {
            try {
                Storage::disk($attachment->disk)->delete($attachment->path);
            } catch (Throwable $e) {
                Log::warning('Could not delete a chat attachment file during channel deletion.', [
                    'attachment_id' => $attachment->id,
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                ]);
            }
        }

        $conversationId = (int) $conversation->id;

        DB::transaction(function () use ($conversation, $conversationId, $messageIds, $participantCount, $attachments): void {
            ChatReaction::query()->whereIn('message_id', $messageIds)->delete();
            MessageEntityLink::query()->whereIn('message_id', $messageIds)->delete();
            ChatMessageAttachment::query()->whereIn('message_id', $messageIds)->delete();
            ChatConversationMessage::query()->whereIn('id', $messageIds)->forceDelete();
            ChatParticipant::query()->where('conversation_id', $conversationId)->delete();
            $conversation->delete();

            // Counts and names, never bodies — same rule as deleteMessage.
            $this->audit('chat.channel_deleted', self::AUDIT_ENTITY_CONVERSATION, $conversationId, [
                'conversation_type' => ChatConversation::TYPE_CHANNEL,
                'name' => $conversation->name,
                'slug' => $conversation->slug,
                'message_count' => $messageIds->count(),
                'participant_count' => $participantCount,
                'attachment_count' => $attachments->count(),
            ]);
        });
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

            // After commit, so no subscriber can ever receive a message that a
            // later failure in this transaction rolled back.
            DB::afterCommit(function () use ($message, $conversation, $user, $body): void {
                $message->loadMissing(['user', 'attachments', 'entityLinks.linkable']);

                NewChatMessage::dispatch($message);

                // Notifications happen after commit as well: a row that later
                // rolls back must not become a notification, and a message that
                // later fails must not wake someone who will then ask about it.
                $this->notifyForNewMessage($message, $conversation, $user, $body);
            });

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

        DB::afterCommit(static function () use ($message): void {
            $message->loadMissing(['user', 'attachments', 'entityLinks.linkable']);

            ChatMessageEdited::dispatch($message);
        });

        return $message;
    }

    /**
     * Soft delete. The row survives so its thread keeps its anchor and the UI
     * can render "[deleted]" rather than losing the replies.
     */
    public function deleteMessage(ChatConversationMessage $message): void
    {
        // Read the identifiers before the delete: the event carries ids only,
        // never the retracted text.
        $messageId = (int) $message->id;
        $conversationId = (int) $message->conversation_id;
        $parentId = $message->parent_id === null ? null : (int) $message->parent_id;

        // Length, not text: the audit records THAT something was retracted, and
        // retracting a message must not copy it somewhere it survives.
        $bodyLength = mb_strlen((string) $message->body);
        $authorId = $message->user_id === null ? null : (int) $message->user_id;

        $message->delete();

        $this->audit('chat.message_deleted', self::AUDIT_ENTITY_MESSAGE, $messageId, [
            'conversation_id' => $conversationId,
            'conversation_type' => $message->conversation?->type,
            'author_user_id' => $authorId,
            'body_length' => $bodyLength,
            'was_thread_reply' => $parentId !== null,
        ]);

        DB::afterCommit(static fn () => ChatMessageDeleted::dispatch($messageId, $conversationId, $parentId));
    }

    /**
     * Attach a reference to a domain entity to a message.
     *
     * The type key is resolved through MessageEntityLink's whitelist and the
     * row is confirmed to exist before anything is written: a polymorphic
     * column filled from request input is otherwise a way to point a record at
     * an arbitrary class, and a dangling id renders as a card for something
     * that was never there.
     */
    public function linkEntity(ChatConversationMessage $message, string $typeKey, int $entityId): MessageEntityLink
    {
        $class = MessageEntityLink::classFor($typeKey);

        if ($class === null) {
            throw new InvalidArgumentException("[{$typeKey}] is not something a message can reference.");
        }

        if (! $class::query()->whereKey($entityId)->exists()) {
            throw new ModelNotFoundException;
        }

        $existing = MessageEntityLink::query()
            ->where('message_id', $message->id)
            ->where('linkable_type', $class)
            ->where('linkable_id', $entityId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return MessageEntityLink::create([
            'message_id' => $message->id,
            'linkable_type' => $class,
            'linkable_id' => $entityId,
        ]);
    }

    public function unlinkEntity(MessageEntityLink $link): void
    {
        $link->delete();
    }

    // --- audit trail ------------------------------------------------------

    /**
     * Record a chat action in `audit_log`.
     *
     * `audit_log` rather than `activity_log` for two reasons: it is the table
     * with the entity_type/entity_id pair these actions need (a chat action is
     * about a conversation or a message, not about a customer), and
     * `activity_log` is read back to the customer on the client dashboard —
     * internal staff chat administration has no business appearing there.
     *
     * METADATA ONLY. Nothing written here may contain a message body: this
     * table is long-lived, widely readable, and exported. Identifiers, counts
     * and lengths describe what happened without republishing what was said.
     *
     * Authorisation happens in the policy before any of these methods is
     * reached, so a refused attempt never gets far enough to write a row.
     *
     * Never throws: an audit trail that can break a conversation is worse than
     * one with a gap in it, and the gap is visible in the log.
     *
     * @param  array<string, mixed>  $details
     */
    private function audit(
        string $action,
        string $entityType,
        ?int $entityId,
        array $details = [],
        ?User $actor = null,
    ): void {
        try {
            $request = app('request');

            AuditLog::create([
                'user_id' => $actor?->id ?? $request?->user()?->id ?? auth()->id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details !== [] ? json_encode($details) : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            try {
                Log::warning('ChatService: audit_log insert failed.', [
                    'action' => $action,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            } catch (Throwable) {
            }
        }
    }

    // --- customer inbox ---------------------------------------------------

    /**
     * Open a customer conversation and post its first message.
     *
     * `$identity` is either ['customer_id' => n] for a signed-in customer, or
     * ['name' => …, 'email' => …] for a guest. A guest_token is minted either
     * way so the widget has one handle to present, and it is written with
     * forceFill because it is not mass-assignable — it is the only credential a
     * guest ever has.
     *
     * The conversation starts unassigned and `waiting`: that IS the operator
     * queue. There is no separate queue table to drift out of step with it.
     */
    public function startCustomerChat(array $identity, ?string $department, string $body): ChatConversation
    {
        $customer = null;

        if (! empty($identity['customer_id'])) {
            $customer = Customer::findOrFail((int) $identity['customer_id']);
        } elseif (empty($identity['email'])) {
            throw new InvalidArgumentException('A customer chat needs either a customer or a guest email.');
        }

        $department = $this->normaliseDepartment($department);
        $token = Str::random(40);

        return DB::transaction(function () use ($identity, $customer, $department, $body, $token) {
            $conversation = new ChatConversation([
                'type' => ChatConversation::TYPE_CUSTOMER_INBOX,
                'department' => $department,
                'customer_id' => $customer?->id,
                'guest_name' => $customer === null ? ($identity['name'] ?? null) : null,
                'guest_email' => $customer === null ? ($identity['email'] ?? null) : null,
                'status' => ChatConversation::STATUS_WAITING,
            ]);
            $conversation->guest_token = $token;
            $conversation->save();

            $participant = new ChatParticipant([
                'conversation_id' => $conversation->id,
                'user_id' => $customer?->user_id,
                'role' => ChatParticipant::ROLE_MEMBER,
                'joined_at' => now(),
            ]);
            $participant->guest_token = $token;
            $participant->save();

            $this->sendMessage(
                $conversation,
                $customer?->user,
                $body,
                null,
                $customer === null ? $token : null,
            );

            // After commit, and announced separately from the message that
            // opened it. NewChatMessage goes to `chat.conversation.{id}`, which
            // nobody is subscribed to yet — this room has no staff participant
            // and no operator has opened it — so without this the customer sits
            // in the queue until somebody happens to reload /admin/chat.
            DB::afterCommit(static function () use ($conversation): void {
                CustomerChatWaiting::dispatch($conversation);
            });

            return $conversation->fresh();
        });
    }

    /**
     * Is this token the one this conversation was opened with?
     *
     * hash_equals rather than ===: token comparison is the one place in this
     * service where a timing difference is worth avoiding.
     */
    public function guestTokenMatches(ChatConversation $conversation, ?string $token): bool
    {
        if ($token === null || $conversation->guest_token === null) {
            return false;
        }

        return hash_equals((string) $conversation->guest_token, $token);
    }

    /**
     * Take a queued conversation, or hand it to someone else.
     *
     * The operator assignment notifies the assignee (gated on
     * `chat.assign`). Self-assignment notifies nobody: you already know.
     * The optional $actor lets the caller say who did the assigning when they
     * are not the assignee; when omitted the operator is assumed to have
     * assigned themselves.
     */
    public function assignOperator(ChatConversation $conversation, User $operator, ?User $actor = null): ChatConversation
    {
        if (! $conversation->isCustomerInbox()) {
            throw new InvalidArgumentException('Only a customer conversation has an operator.');
        }

        if ($conversation->status === ChatConversation::STATUS_CLOSED) {
            throw new RuntimeException('This conversation is closed.');
        }

        $actor = $actor ?? $operator;

        return DB::transaction(function () use ($conversation, $operator, $actor) {
            $conversation->forceFill([
                'assigned_operator_id' => $operator->id,
                'status' => ChatConversation::STATUS_ACTIVE,
            ])->save();

            // Assignment is its own event; the seat it grants is not a separate
            // membership change.
            $this->addMember($conversation, $operator, recordAudit: false);

            DB::afterCommit(function () use ($conversation, $operator, $actor): void {
                $this->notifyForAssignment($conversation, $operator, $actor);
            });

            return $conversation;
        });
    }

    /**
     * Move a conversation to another department and put it back in the queue.
     *
     * The previous operator is unassigned but stays a participant: they can
     * still read what they were part of, which is what makes a transfer a
     * handover rather than a disappearance.
     */
    public function transferConversation(ChatConversation $conversation, string $department): ChatConversation
    {
        if (! $conversation->isCustomerInbox()) {
            throw new InvalidArgumentException('Only a customer conversation can be transferred.');
        }

        if ($conversation->status === ChatConversation::STATUS_CLOSED) {
            throw new RuntimeException('This conversation is closed.');
        }

        $from = $conversation->department;
        $previousOperator = $conversation->assigned_operator_id;

        $conversation->forceFill([
            'department' => $this->normaliseDepartment($department),
            'assigned_operator_id' => null,
            'status' => ChatConversation::STATUS_WAITING,
        ])->save();

        // A handover moves a customer between teams and drops the operator who
        // was on it. Close and convert were both recorded; this was the one
        // lifecycle change that left no trace of who used to own the room.
        $this->audit('chat.conversation_transferred', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'customer_id' => $conversation->customer_id,
            'from_department' => $from,
            'to_department' => $conversation->department,
            'previous_operator_id' => $previousOperator,
        ]);

        return $conversation;
    }

    public function closeConversation(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill([
            'status' => ChatConversation::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        $this->audit('chat.conversation_closed', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'customer_id' => $conversation->customer_id,
            'assigned_operator_id' => $conversation->assigned_operator_id,
            'department' => $conversation->department,
            'closed_at' => $conversation->closed_at?->toDateTimeString(),
        ]);

        // The transcript email, if the install has asked for one.
        //
        // After commit so a close that later rolls back cannot have already
        // emailed the customer a transcript of a conversation that is still
        // open. Inside a try/catch because mail is not part of closing: an
        // operator clicking Close must not be told the close failed because the
        // template was deleted or the queue table is unreachable. The service
        // itself decides whether the feature is on and skips quietly when it is
        // not, so the common path is a single settings read.
        DB::afterCommit(function () use ($conversation): void {
            try {
                $this->transcripts->send($conversation);
            } catch (Throwable $e) {
                Log::warning('ChatService: transcript email failed.', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $conversation;
    }

    /**
     * The customer's rating, 1-5, recorded once the conversation is over.
     */
    public function rateConversation(ChatConversation $conversation, int $rating): ChatConversation
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('A rating is between 1 and 5.');
        }

        if ($conversation->status !== ChatConversation::STATUS_CLOSED) {
            throw new RuntimeException('A conversation can only be rated once it is closed.');
        }

        $conversation->forceFill(['rating' => $rating])->save();

        // Recorded without an actor on purpose: the customer is a guest with no
        // user row, so audit() will resolve user_id to null here. That is the
        // honest answer — inventing the closing operator as the actor would
        // attribute their own score to them.
        $this->audit('chat.conversation_rated', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'customer_id' => $conversation->customer_id,
            'assigned_operator_id' => $conversation->assigned_operator_id,
            'rating' => $rating,
        ]);

        return $conversation;
    }

    /**
     * Turn a chat into a ticket, carrying the transcript across.
     *
     * Delegates to TicketService::create() rather than writing ticket rows
     * here: that method owns ticket numbering, the guest/customer resolution
     * and the TicketCreated event, and a second creator would drift from it.
     *
     * The chat is NOT closed and its lifecycle is not otherwise touched — the
     * conversation simply gains a reference to the ticket it produced.
     */
    public function convertToTicket(ChatConversation $conversation, TicketService $tickets): Ticket
    {
        if (! $conversation->isCustomerInbox()) {
            throw new InvalidArgumentException('Only a customer conversation can become a ticket.');
        }

        $messages = $conversation->messages()->with('user')->orderBy('id')->get();

        if ($messages->isEmpty()) {
            throw new RuntimeException('There is nothing to convert — this conversation is empty.');
        }

        $first = $messages->first();
        $subject = Str::limit(trim((string) $first->body), 70, '...');

        $transcript = $messages
            ->map(fn (ChatConversationMessage $m) => sprintf(
                '[%s] %s: %s',
                $m->created_at?->toDateTimeString() ?? '',
                $m->authorName(),
                $m->visibleBody(),
            ))
            ->implode("\n");

        $ticket = $tickets->create(
            [
                'customer_id' => $conversation->customer_id,
                'guest_email' => $conversation->customer_id === null ? $conversation->guest_email : null,
                'guest_name' => $conversation->customer_id === null ? $conversation->guest_name : null,
                'subject' => $subject !== '' ? $subject : 'Chat conversation',
                'department' => $conversation->department ?? array_key_first(TicketService::departments()),
                'assigned_to' => $conversation->assigned_operator_id,
            ],
            $transcript,
        );

        MessageEntityLink::create([
            'message_id' => $first->id,
            'linkable_type' => Ticket::class,
            'linkable_id' => $ticket->id,
            'created_at' => now(),
        ]);

        // The transcript itself is deliberately absent: the ticket already
        // holds it, and the audit trail is a list of what happened.
        $this->audit('chat.converted_to_ticket', self::AUDIT_ENTITY_CONVERSATION, $conversation->id, [
            'conversation_type' => $conversation->type,
            'customer_id' => $conversation->customer_id,
            'ticket_id' => $ticket->id,
            'ticket_no' => $ticket->ticket_no,
            'message_count' => $messages->count(),
        ]);

        return $ticket;
    }

    /**
     * Store an uploaded file against a message.
     *
     * The disk name and the disk-relative path are recorded separately and the
     * absolute path is never persisted, so the rows survive the application
     * being moved or the disk repointed. Files go on the `local` disk — under
     * storage/app/private, outside the web root — and are served only through
     * a signed, authorised route.
     *
     * The stored filename is generated; the user's filename is kept as data.
     * A user-supplied name on disk is a path-traversal surface and nothing else.
     */
    public function attachFile(ChatConversationMessage $message, UploadedFile $file, bool $inline = false): ChatMessageAttachment
    {
        $disk = 'local';
        $path = $file->store('chat-attachments/'.$message->conversation_id, $disk);

        if ($path === false) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        return ChatMessageAttachment::create([
            'message_id' => $message->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'is_inline' => $inline,
        ]);
    }

    /**
     * Move a participant's read cursor forward.
     *
     * Forward only. A late-arriving "I have read up to 12" from a tab that was
     * behind must not undo a "read up to 30" from the tab in front, or the
     * unread badge flickers back on for messages the person has already seen.
     *
     * @return int the cursor after the call
     */
    public function markRead(ChatConversation $conversation, User $user, ?int $messageId = null): int
    {
        $participant = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant === null) {
            // Reading a public channel you have not joined is allowed, but
            // there is no row to carry a cursor, and creating one would silently
            // enrol you.
            return 0;
        }

        $target = $messageId ?? (int) $conversation->messages()->max('id');
        $current = (int) $participant->last_read_message_id;

        if ($target <= $current) {
            return $current;
        }

        $participant->forceFill(['last_read_message_id' => $target])->save();

        return $target;
    }

    /**
     * How many messages in this conversation this user has not seen.
     *
     * Own messages never count: you do not have unread mail from yourself.
     */
    public function unreadCount(ChatConversation $conversation, User $user): int
    {
        $participant = ChatParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant === null) {
            return 0;
        }

        return $conversation->messages()
            ->where('id', '>', (int) $participant->last_read_message_id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->count();
    }

    /**
     * Unread counts for every conversation this user belongs to, keyed by
     * conversation id.
     *
     * One grouped query rather than one per conversation: this runs on every
     * sidebar render and on every poll of the fallback endpoint.
     *
     * @return array<int, int>
     */
    public function unreadCounts(User $user): array
    {
        return ChatParticipant::query()
            ->where('chat_participants.user_id', $user->id)
            ->join('chat_conversation_messages as m', function ($join) use ($user) {
                $join->on('m.conversation_id', '=', 'chat_participants.conversation_id')
                    ->whereColumn('m.id', '>', DB::raw('coalesce(chat_participants.last_read_message_id, 0)'))
                    ->whereNull('m.deleted_at')
                    ->where(function ($q) use ($user) {
                        $q->whereNull('m.user_id')->orWhere('m.user_id', '!=', $user->id);
                    });
            })
            ->groupBy('chat_participants.conversation_id')
            ->selectRaw('chat_participants.conversation_id as conversation_id, count(m.id) as unread')
            ->pluck('unread', 'conversation_id')
            ->map(static fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Add a reaction, or take it off if this user already left that emoji.
     *
     * The unique index on (message, user, emoji) is what makes this a
     * delete-or-insert rather than a counter, so it cannot drift out of step
     * with the rows it is meant to summarise.
     *
     * @return array{added: bool, count: int}
     */
    public function toggleReaction(ChatConversationMessage $message, User $user, string $emoji): array
    {
        if (! ChatReaction::isAllowed($emoji)) {
            throw new InvalidArgumentException('That is not a reaction this chat supports.');
        }

        $existing = ChatReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->first();

        if ($existing !== null) {
            $existing->delete();
            $added = false;
        } else {
            ChatReaction::create([
                'message_id' => $message->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
            $added = true;
        }

        $count = ChatReaction::query()
            ->where('message_id', $message->id)
            ->where('emoji', $emoji)
            ->count();

        DB::afterCommit(static fn () => ReactionToggled::dispatch(
            (int) $message->id,
            (int) $message->conversation_id,
            (int) $user->id,
            $emoji,
            $added,
            $count,
        ));

        return ['added' => $added, 'count' => $count];
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

    // --- notifications (Todo 16) ---------------------------------------------

    private function notifyForNewMessage(
        ChatConversationMessage $message,
        ChatConversation $conversation,
        ?User $author,
        string $body,
    ): void {
        if ($author === null) {
            // Guest messages never generate mention/reply notifications — there
            // is no actor to attribute and no staff identity to match.
            return;
        }

        // Collect everyone already notified via @mention so a thread reply that
        // is also an @mention does not double-notify the same person.
        $mentionedIds = collect();

        // @mentions
        $mentioned = ChatMentions::mentionedUsers($body, $conversation, $author);

        foreach ($mentioned as $user) {
            if (! $this->preferences->isEnabled($user, 'chat.mention')) {
                continue;
            }

            $user->notify(new ChatMentionNotification($message, $conversation, (int) $author->id, $author->full_name));
            $mentionedIds->push($user->id);
        }

        // Thread reply — the author of the parent message, if anyone.
        if ($message->parent_id !== null) {
            $parent = ChatConversationMessage::find($message->parent_id);

            if ($parent !== null && $parent->user_id !== null && (int) $parent->user_id !== (int) $author->id) {
                if (! $mentionedIds->contains((int) $parent->user_id)) {
                    $parentAuthor = User::find($parent->user_id);

                    if ($parentAuthor !== null
                        && $parentAuthor->can('view', $conversation)
                        && $this->preferences->isEnabled($parentAuthor, 'chat.reply')) {
                        $parentAuthor->notify(new ChatReplyNotification($message, $parent, $conversation, (int) $author->id, $author->full_name));
                    }
                }
            }
        }
    }

    private function notifyForAssignment(ChatConversation $conversation, User $operator, User $actor): void
    {
        if ((int) $operator->id === (int) $actor->id) {
            return;
        }

        if (! $operator->can('view', $conversation)) {
            return;
        }

        if (! $this->preferences->isEnabled($operator, 'chat.assign')) {
            return;
        }

        $operator->notify(new ChatAssignmentNotification($conversation, (int) $actor->id, $actor->full_name));
    }
}
