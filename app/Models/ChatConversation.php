<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation in the Slack-like chat: a channel, a 1:1 DM, a group DM, or a
 * customer inbox.
 *
 * `guest_token` is deliberately absent from the fillable list. It is the only
 * credential a guest ever presents, so it is written explicitly by
 * ChatService and can never be set from request input.
 */
#[Fillable([
    'type', 'name', 'slug', 'is_private', 'department', 'topic', 'purpose', 'created_by',
    'customer_id', 'assigned_operator_id', 'guest_name', 'guest_email',
    'status', 'rating', 'closed_at', 'archived_at',
])]
class ChatConversation extends Model
{
    use HasFactory;

    public const TYPE_CHANNEL = 'channel';

    public const TYPE_DM = 'dm';

    public const TYPE_GROUP_DM = 'group_dm';

    public const TYPE_CUSTOMER_INBOX = 'customer_inbox';

    public const TYPES = [self::TYPE_CHANNEL, self::TYPE_DM, self::TYPE_GROUP_DM, self::TYPE_CUSTOMER_INBOX];

    /** Conversation types staff talk to each other in — never a customer inbox. */
    public const STAFF_TYPES = [self::TYPE_CHANNEL, self::TYPE_DM, self::TYPE_GROUP_DM];

    public const STATUS_WAITING = 'waiting';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'rating' => 'integer',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatConversationMessage::class, 'conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_operator_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCustomerInbox(): bool
    {
        return $this->type === self::TYPE_CUSTOMER_INBOX;
    }

    /**
     * A display label that works for every conversation type: channels have a
     * name, DMs do not.
     */
    public function displayName(): string
    {
        if (($this->name ?? '') !== '') {
            return (string) $this->name;
        }

        if ($this->isCustomerInbox()) {
            return $this->customer?->full_name
                ?? ($this->guest_name ?: $this->guest_email ?: 'Guest');
        }

        return $this->participants
            ->map(fn (ChatParticipant $p) => $p->user?->full_name ?? $p->user?->email)
            ->filter()
            ->implode(', ') ?: 'Conversation';
    }

    /**
     * Can people be added to or removed from this conversation at all?
     *
     * A 1:1 direct message IS its two people: findOrCreateDirectMessage()
     * matches on exactly that pair, so adding a third or dropping one leaves a
     * room that is neither a DM nor a group and that nobody can reopen.
     *
     * A structural fact, not a permission — which is why it is asked here and
     * not only in the policy. The AdminLTE package registers a Gate::before
     * that returns true for every ability an admin asks about, so a policy
     * denial alone would be invisible to exactly the people most likely to
     * click the button.
     */
    public function allowsMembershipChanges(): bool
    {
        return in_array($this->type, [self::TYPE_CHANNEL, self::TYPE_GROUP_DM], true);
    }

    /** Only a public, unfrozen channel can be walked into uninvited. */
    public function isSelfJoinable(): bool
    {
        return $this->type === self::TYPE_CHANNEL && ! $this->is_private && ! $this->isArchived();
    }

    /**
     * The label to show to one particular person.
     *
     * A direct message named after everyone in it reads "You, Ana" in your own
     * sidebar — one word of information and one of noise. displayName() stays
     * viewer-agnostic on purpose: the audit log, the transcript email and the
     * operator queue have no viewer to drop.
     */
    public function labelFor(?User $viewer): string
    {
        if ($viewer === null || ! in_array($this->type, [self::TYPE_DM, self::TYPE_GROUP_DM], true)) {
            return $this->displayName();
        }

        if (($this->name ?? '') !== '') {
            return (string) $this->name;
        }

        $others = $this->participants
            ->reject(fn (ChatParticipant $participant) => (int) $participant->user_id === (int) $viewer->id)
            ->map(fn (ChatParticipant $participant) => $participant->user?->full_name ?? $participant->user?->email)
            ->filter();

        return $others->implode(', ') ?: $this->displayName();
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }
}
