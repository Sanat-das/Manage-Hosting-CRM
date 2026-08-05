<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Support-ticket domain logic.
 *
 * Ported from the reference (modules/tickets/TicketModel.php — the working
 * legacy path) with the local enum `open/pending/resolved/closed`:
 *
 * - staff reply  -> status 'pending' (resolution pending on our side)
 * - client reply -> status 'open'    (needs staff attention again)
 * - close        -> 'closed', reopen -> 'open'
 * - assign       -> 'open' (reference domain maps assignment to `in_progress`;
 *                  the local enum has no such value, so 'open' is used)
 *
 * Internal notes: `ticket_replies` has no dedicated note column, so notes use
 * the convention `is_staff = true` + a message prefixed with
 * {@see self::INTERNAL_NOTE_PREFIX}. They never touch status / last_reply_at
 * and are excluded from customer-facing views and the API.
 */
class TicketService
{
    /** Marker prefix that flags a ticket_reply row as an internal note. */
    public const INTERNAL_NOTE_PREFIX = '[INTERNAL]';

    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    /** @var array<string, string> */
    public const DEPARTMENTS = [
        'sales' => 'Sales',
        'support' => 'Support',
        'billing' => 'Billing',
        'technical' => 'Technical',
    ];

    /** @var array<string, string> */
    public const PRIORITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /**
     * Create a ticket with its initial (customer-authored) message.
     *
     * @param  array<string, mixed>  $data  customer_id, subject, department, priority, assigned_to
     */
    public function create(array $data, string $message): Ticket
    {
        return DB::transaction(function () use ($data, $message) {
            $customer = Customer::findOrFail((int) $data['customer_id']);

            $ticket = Ticket::create([
                'ticket_no' => $this->nextTicketNumber(),
                'customer_id' => $customer->id,
                'subject' => $data['subject'],
                'priority' => $data['priority'],
                'status' => self::STATUS_OPEN,
                'department' => $data['department'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'last_reply_at' => now(),
            ]);

            TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $customer->user_id,
                'message' => $message,
                'is_staff' => false,
            ]);

            return $ticket;
        });
    }

    /**
     * Add a public reply. Staff replies move the ticket to 'pending',
     * customer replies back to 'open' (reference addReply logic).
     *
     * @throws DomainException when the ticket is closed
     */
    public function reply(Ticket $ticket, User $user, string $message): TicketReply
    {
        $this->assertOpenForReply($ticket);

        return DB::transaction(function () use ($ticket, $user, $message) {
            $isStaff = $this->isStaff($user);

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $message,
                'is_staff' => $isStaff,
            ]);

            $ticket->update([
                'status' => $isStaff ? self::STATUS_PENDING : self::STATUS_OPEN,
                'last_reply_at' => now(),
            ]);

            return $reply;
        });
    }

    /**
     * Add an internal note (staff-only). Keeps status and last_reply_at
     * untouched so it never shows up as customer-facing activity.
     */
    public function addNote(Ticket $ticket, User $user, string $note): TicketReply
    {
        return TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => self::INTERNAL_NOTE_PREFIX.' '.trim($note),
            'is_staff' => true,
        ]);
    }

    /**
     * @throws DomainException when the ticket is already closed
     */
    public function close(Ticket $ticket): Ticket
    {
        if ($ticket->status === self::STATUS_CLOSED) {
            throw new DomainException('Ticket is already closed.');
        }

        $ticket->update(['status' => self::STATUS_CLOSED]);

        return $ticket;
    }

    /**
     * @throws DomainException when the ticket is not closed
     */
    public function reopen(Ticket $ticket): Ticket
    {
        if ($ticket->status !== self::STATUS_CLOSED) {
            throw new DomainException('Only closed tickets can be reopened.');
        }

        $ticket->update(['status' => self::STATUS_OPEN]);

        return $ticket;
    }

    /**
     * Assign a staff member. Reopens closed tickets; the reference domain
     * model moves assignment to `in_progress`, which the local enum maps to
     * 'open' (see class docblock).
     */
    public function assign(Ticket $ticket, ?int $userId): Ticket
    {
        $ticket->update([
            'assigned_to' => $userId,
            'status' => self::STATUS_OPEN,
        ]);

        return $ticket;
    }

    /**
     * Whether a user is a staff member (anything that is not a client role).
     */
    public function isStaff(User $user): bool
    {
        return $user->role !== 'client';
    }

    /**
     * @throws DomainException when the ticket is closed
     */
    private function assertOpenForReply(Ticket $ticket): void
    {
        if ($ticket->status === self::STATUS_CLOSED) {
            throw new DomainException('Closed tickets must be reopened before replying.');
        }
    }

    /**
     * Sequential ticket number from the support settings (ticket_prefix +
     * ticket_next_number), matching the billing invoice-number pattern.
     * Must run inside a transaction so the row lock is effective.
     */
    private function nextTicketNumber(): string
    {
        $setting = Setting::query()
            ->where('setting_key', 'ticket_next_number')
            ->lockForUpdate()
            ->first();

        $next = max(1, (int) ($setting?->setting_value ?? 1));
        $prefix = (string) (Setting::where('setting_key', 'ticket_prefix')->value('setting_value') ?? 'TKT-');

        if ($setting !== null) {
            $setting->update(['setting_value' => (string) ($next + 1)]);
        } else {
            Setting::create([
                'setting_key' => 'ticket_next_number',
                'setting_value' => (string) ($next + 1),
                'group' => 'support',
            ]);
        }

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
