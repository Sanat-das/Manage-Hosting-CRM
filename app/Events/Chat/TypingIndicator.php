<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\ChatConversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone started or stopped typing.
 *
 * Carries no message text at all — a keystroke-level feed of what a colleague
 * is part-way through writing is not something the wire needs to see.
 *
 * Two audiences, two channels. Staff read the presence channel, which is what
 * lets their client learn that someone stopped without a heartbeat of its own.
 * A customer cannot: a presence channel publishes its member roster, so putting
 * a guest on one would hand them a list of staff. When an operator types into a
 * customer inbox the event is therefore ALSO published on that conversation's
 * private channel — the one channel a guest token already entitles its holder
 * to, and exactly one conversation's worth of it.
 *
 * That second channel is added only for a customer inbox, and only for a staff
 * typer:
 *   - staff channels and DMs keep emitting on the presence channel alone, so no
 *     guest is ever subscribed to anything carrying staff typing, and staff
 *     rooms gain no second copy of their own traffic;
 *   - the customer's own heartbeat (userId 0) is not published back to the
 *     customer, which would otherwise show the widget its own typing.
 */
class TypingIndicator implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * The user id the customer widget's heartbeat carries. A guest has no user
     * row, and inventing one would put a fake row-shaped id on a channel staff
     * read — see ChatWidgetController::typing().
     */
    private const CUSTOMER = 0;

    /**
     * What the customer is told instead of the operator's name.
     *
     * The transcript already hides which operator replied
     * (ChatWidgetController::clientPayload unsets the user outright), so naming
     * them here would both contradict that and leak a staff member's real name
     * into an unauthenticated visitor's browser.
     */
    private const OPERATOR_LABEL = 'Support';

    /** Memoised: broadcastOn() and broadcastWith() both ask. */
    private ?bool $reachesCustomer = null;

    public function __construct(
        public int $conversationId,
        public int $userId,
        public string $userName,
        public bool $typing,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PresenceChannel('chat.typing.'.$this->conversationId)];

        if ($this->reachesCustomer()) {
            $channels[] = new PrivateChannel('chat.conversation.'.$this->conversationId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.typing';
    }

    /**
     * One payload serves both channels, so it may not contain anything the
     * customer must not see. `user_id` stays real because the admin pane uses it
     * to recognise — and ignore — its own typing; it is a bare integer with no
     * name, address or username attached to it.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'user_name' => $this->reachesCustomer() ? self::OPERATOR_LABEL : $this->userName,
            'typing' => $this->typing,
        ];
    }

    /**
     * Is this a staff member typing where a customer is listening?
     *
     * A conversation that no longer exists answers no, which keeps a stale
     * event from ever widening its own audience.
     */
    private function reachesCustomer(): bool
    {
        if ($this->reachesCustomer !== null) {
            return $this->reachesCustomer;
        }

        if ($this->userId === self::CUSTOMER) {
            return $this->reachesCustomer = false;
        }

        return $this->reachesCustomer = ChatConversation::find($this->conversationId)?->isCustomerInbox() === true;
    }
}
