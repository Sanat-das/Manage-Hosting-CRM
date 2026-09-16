<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An operator's declared state.
 *
 * `available` is the absent case as well as a stored value: a user with no row
 * here is available, which is what keeps this feature from changing anything
 * for an install that never uses it. That equivalence lives in
 * ChatAvailability::stateFor(), not in a `??` at each call site.
 */
#[Fillable(['user_id', 'state', 'note', 'state_changed_at'])]
class ChatOperatorAvailability extends Model
{
    /** Model name is already plural-ish; Laravel would ask for a third "i". */
    protected $table = 'chat_operator_availability';

    public const STATE_AVAILABLE = 'available';

    public const STATE_AWAY = 'away';

    public const STATE_BUSY = 'busy';

    public const STATES = [self::STATE_AVAILABLE, self::STATE_AWAY, self::STATE_BUSY];

    /** What each state is called in the UI. */
    public const LABELS = [
        self::STATE_AVAILABLE => 'Available',
        self::STATE_AWAY => 'Away',
        self::STATE_BUSY => 'Busy',
    ];

    protected function casts(): array
    {
        return [
            'state_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Would a new customer conversation be offered to this person?
     *
     * Only `available` accepts. `busy` is deliberately not "accepting but
     * slower": an operator who says they are busy and is then handed the queue
     * anyway has a control that does nothing.
     */
    public function isAccepting(): bool
    {
        return $this->state === self::STATE_AVAILABLE;
    }

    public function label(): string
    {
        return self::LABELS[$this->state] ?? self::LABELS[self::STATE_AVAILABLE];
    }
}
