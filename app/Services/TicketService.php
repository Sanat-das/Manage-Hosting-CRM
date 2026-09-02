<?php

namespace App\Services;

use App\Events\TicketCreated;
use App\Events\TicketReply as TicketReplyEvent;
use App\Events\TicketTransferred;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\TicketTransfer;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Support-ticket domain logic.
 *
 * WHMCS-style status set (open/answered/customer_reply/on_hold/in_progress/
 * closed — see 2026_09_01_000001_switch_tickets_status_to_whmcs_enum):
 *
 * - staff reply  -> status 'answered'       (waiting on the customer)
 * - client reply -> status 'customer_reply' (waiting on staff)
 * - close        -> 'closed', reopen -> 'open'
 * - assign       -> 'open'
 * - on_hold / in_progress are set manually by staff via {@see self::setStatus()},
 *   never assigned automatically
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

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_CUSTOMER_REPLY = 'customer_reply';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    /** Statuses a staff member may set by hand — see {@see self::setStatus()}. */
    public const MANUAL_STATUSES = [self::STATUS_ON_HOLD, self::STATUS_IN_PROGRESS];

    /**
     * The original four departments.
     *
     * Kept as the seed source for the ticket_departments table and as the
     * fallback for {@see self::departments()} when that table is unavailable
     * (early boot, a half-migrated database). Read departments() instead of
     * this constant — an admin can add, rename or disable departments.
     *
     * @var array<string, string>
     */
    public const DEPARTMENTS = [
        'sales' => 'Sales',
        'support' => 'Support',
        'billing' => 'Billing',
        'technical' => 'Technical',
    ];

    /** @var array<string, string>|null request-level cache for departments() */
    private static ?array $departmentCache = null;

    /**
     * Selectable departments as `slug => label`, the shape every ticket form,
     * filter and validation rule already expects.
     *
     * Disabled departments are excluded so they stop appearing on new tickets,
     * but existing tickets still resolve their label through
     * {@see self::departmentLabel()}.
     *
     * @return array<string, string>
     */
    public static function departments(): array
    {
        if (self::$departmentCache !== null) {
            return self::$departmentCache;
        }

        try {
            $departments = TicketDepartment::query()
                ->enabled()
                ->ordered()
                ->pluck('name', 'slug')
                ->all();
        } catch (\Throwable $e) {
            // No table yet (fresh install mid-migrate) — the four originals
            // keep the ticket screens working.
            return self::DEPARTMENTS;
        }

        return self::$departmentCache = $departments !== [] ? $departments : self::DEPARTMENTS;
    }

    /**
     * Label for a stored slug, including departments that have since been
     * disabled or renamed, so historical tickets never render a bare slug.
     */
    public static function departmentLabel(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return '';
        }

        $known = self::departments();

        if (isset($known[$slug])) {
            return $known[$slug];
        }

        try {
            $name = TicketDepartment::query()->where('slug', $slug)->value('name');
        } catch (\Throwable $e) {
            $name = null;
        }

        return $name ?? self::DEPARTMENTS[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
    }

    /**
     * Drop the request-level cache. Needed after a department is created,
     * edited or deleted within the same request, and by tests.
     */
    public static function forgetDepartmentCache(): void
    {
        self::$departmentCache = null;
    }

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
     * @param  array<string, mixed>  $replyAttributes  extra columns for the opening reply.
     *                                                 Applied BEFORE TicketCreated fires, which
     *                                                 matters for a ticket opened from an email:
     *                                                 the acknowledgement threads against the
     *                                                 sender's Message-ID, so it has to be stored
     *                                                 by the time the listener builds that mail.
     */
    public function create(array $data, string $message, array $replyAttributes = []): Ticket
    {
        return DB::transaction(function () use ($data, $message, $replyAttributes) {
            $customer = null;
            $guestEmail = $data['guest_email'] ?? null;
            $guestName = $data['guest_name'] ?? null;
            $userId = null;

            if (! empty($data['customer_id'])) {
                $customer = Customer::findOrFail((int) $data['customer_id']);
                $userId = $customer->user_id;
            } elseif ($guestEmail) {
                $existingUser = User::whereRaw('LOWER(email) = ?', [strtolower($guestEmail)])->first();
                $userId = $existingUser?->id; // nullable allowed since 2026_08_31_000002 migration
            } else {
                throw new \DomainException('customer_id or guest_email is required.');
            }

            $ticket = Ticket::create([
                'ticket_no' => $this->nextTicketNumber(),
                'customer_id' => $customer?->id,
                'guest_email' => $guestEmail,
                'guest_name' => $guestName,
                'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'medium',
                'status' => self::STATUS_OPEN,
                'department' => $data['department'],
                'assigned_to' => $data['assigned_to'] ?? null,
                'last_reply_at' => now(),
            ]);

            TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $userId,
                'message' => $message,
                'is_staff' => false,
                ...$replyAttributes,
            ]);

            TicketCreated::dispatch($ticket);

            return $ticket;
        });
    }

    /**
     * Link a guest ticket to an existing customer (and optionally create a contact).
     */
    public function linkGuestToCustomer(Ticket $ticket, Customer $customer, bool $createContact = false): Ticket
    {
        if ($ticket->customer_id !== null) {
            throw new \DomainException('Ticket is already linked to a customer.');
        }
        $guestEmail = $ticket->guest_email;
        $guestName = $ticket->guest_name;

        return DB::transaction(function () use ($ticket, $customer, $createContact, $guestEmail, $guestName) {
            $ticket->update([
                'customer_id' => $customer->id,
                'guest_email' => null,
                'guest_name' => null,
            ]);

            if ($createContact && $guestEmail) {
                $parts = preg_split('/\s+/', trim((string) $guestName), 2) ?: [];
                // Avoid duplicate contact email
                $exists = \App\Models\CustomerContact::where('customer_id', $customer->id)->whereRaw('LOWER(email) = ?', [strtolower($guestEmail)])->exists();
                if (! $exists) {
                    \App\Models\CustomerContact::create([
                        'customer_id' => $customer->id,
                        'first_name' => $parts[0] ?? 'Guest',
                        'last_name' => $parts[1] ?? '',
                        'email' => $guestEmail,
                        'is_primary' => false,
                        'status' => 'active',
                    ]);
                }
            }

            return $ticket->refresh();
        });
    }

    /**
     * Add the guest sender as a contact on the given customer. Does not link ticket.
     */
    public function addGuestAsContact(Ticket $ticket, Customer $customer, array $overrides = []): \App\Models\CustomerContact
    {
        if (! $ticket->isGuest()) {
            throw new \DomainException('Only guest tickets have a guest to add as contact.');
        }
        $email = $ticket->guest_email;
        $name = $ticket->guest_name ?? $ticket->guest_email;
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        return \App\Models\CustomerContact::create([
            'customer_id' => $customer->id,
            'first_name' => $overrides['first_name'] ?? ($parts[0] ?? 'Guest'),
            'last_name' => $overrides['last_name'] ?? ($parts[1] ?? ''),
            'email' => $overrides['email'] ?? $email,
            'phone' => $overrides['phone'] ?? null,
            'role' => $overrides['role'] ?? null,
            'is_primary' => false,
            'status' => 'active',
        ]);
    }

    /**
     * Add a public reply. Staff replies move the ticket to 'pending',
     * customer replies back to 'open' (reference addReply logic).
     *
     * @param  array<string, mixed>  $attributes  optional `to`/`cc`/`bcc` (list<string>) and
     *                                            `html_body` from a staff compose form. Blank/omitted
     *                                            means "behave exactly as a plain-text reply always has" —
     *                                            `TicketMailService::recipientFor()` still supplies `to`.
     * @param  list<UploadedFile>  $attachments
     * @throws DomainException when the ticket is closed
     */
    public function reply(Ticket $ticket, User $user, string $message, array $attributes = [], array $attachments = []): TicketReply
    {
        $this->assertOpenForReply($ticket);

        return DB::transaction(function () use ($ticket, $user, $message, $attributes, $attachments) {
            $isStaff = $this->isStaff($user);

            $reply = TicketReply::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'message' => $message,
                'is_staff' => $isStaff,
                ...$attributes,
            ]);

            $this->storeUploadedAttachments($reply, $attachments);

            $ticket->update([
                'status' => $isStaff ? self::STATUS_ANSWERED : self::STATUS_CUSTOMER_REPLY,
                'last_reply_at' => now(),
            ]);

            TicketReplyEvent::dispatch($ticket, $reply);

            return $reply;
        });
    }

    /**
     * Save staff-uploaded files from the compose form the same way
     * TicketMailParser::storeAttachments() saves inbound ones — one
     * `ticket_attachments` row per file. `basename()` on the client-supplied
     * filename guards against a crafted name walking outside the ticket's
     * attachment directory, same reasoning as the inbound path.
     *
     * @param  list<UploadedFile>  $attachments
     */
    private function storeUploadedAttachments(TicketReply $reply, array $attachments): void
    {
        foreach ($attachments as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $safeName = basename($file->getClientOriginalName()) ?: "attachment-{$index}";
            $storedPath = $file->storeAs(
                "ticket-attachments/{$reply->ticket_id}/{$reply->id}",
                "{$index}-{$safeName}",
                'local'
            );

            TicketAttachment::create([
                'ticket_reply_id' => $reply->id,
                'disk' => 'local',
                'path' => $storedPath,
                'filename' => $safeName,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'is_inline' => false,
                'content_id' => null,
            ]);
        }
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
     * Manually move a ticket to an admin-only status (On Hold / In Progress).
     * Distinct from close()/reopen(), which own the closed <-> open edge.
     *
     * @throws DomainException when the ticket is closed or $status isn't manual
     */
    public function setStatus(Ticket $ticket, string $status): Ticket
    {
        if (! in_array($status, self::MANUAL_STATUSES, true)) {
            throw new DomainException('Status must be one of: '.implode(', ', self::MANUAL_STATUSES));
        }

        if ($ticket->status === self::STATUS_CLOSED) {
            throw new DomainException('Closed tickets must be reopened before changing status.');
        }

        $ticket->update(['status' => $status]);

        return $ticket;
    }

    /**
     * Assign a staff member. Moves the ticket to 'open'; staff can move it to
     * 'in_progress' separately via {@see self::setStatus()} once they start
     * working it.
     *
     * @throws DomainException when the user does not exist, is a client, or
     *                         is not a member of the ticket's department
     */
    public function assign(Ticket $ticket, ?int $userId): Ticket
    {
        if ($userId !== null) {
            $user = User::find($userId);
            if ($user === null) {
                throw new DomainException('User not found.');
            }
            if (! $this->isStaff($user)) {
                throw new DomainException('Cannot assign a client user.');
            }
            if (! $this->isInDepartment($user, $ticket->department)) {
                throw new DomainException('User is not a member of this ticket\'s department.');
            }
        }

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
     * Delegates to `Ticket::scopeVisibleTo` so callers can apply
     * department-scoped visibility without depending on the scope name.
     */
    public static function applyVisibility(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user);
    }

    /**
     * Whether a user belongs to the given department via pivot.
     * Admin bypasses the check (sees all departments).
     */
    public function isInDepartment(User $user, string $slug): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->ticketDepartments()->where('slug', $slug)->exists();
    }

    /**
     * Transfer a ticket to another department with audit and internal note.
     *
     * 10 steps in one DB::transaction:
     * 1) reject closed, 2) validate target enabled, 3) reject same, 4) validate assignTo in target,
     * 5) capture from, 6) resolve assigned_to (clear if not in target else keep/override),
     * 7) update department, 8) create TicketTransfer, 9) addNote, 10) keep status open.
     *
     * @throws DomainException
     */
    public function transferDepartment(Ticket $ticket, string $targetSlug, User $actor, ?int $assignTo = null, ?string $note = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $targetSlug, $actor, $assignTo, $note): Ticket {
            if ($ticket->status === self::STATUS_CLOSED) {
                throw new DomainException('Reopen before transfer');
            }

            $target = TicketDepartment::where('slug', $targetSlug)->where('enabled', true)->first();
            if ($target === null) {
                throw new DomainException('Target department not found or disabled.');
            }

            if ($targetSlug === $ticket->department) {
                throw new DomainException('Ticket is already in that department.');
            }

            if ($assignTo !== null) {
                $assignUser = User::find($assignTo);
                if ($assignUser === null) {
                    throw new DomainException('Assignee not found.');
                }
                if (! $this->isInDepartment($assignUser, $targetSlug)) {
                    throw new DomainException('Assignee not in target department.');
                }
            }

            $from = $ticket->department;
            $assignedFrom = $ticket->assigned_to;

            $currentInTarget = true;
            if ($ticket->assigned_to !== null) {
                $currentUser = User::find($ticket->assigned_to);
                if ($currentUser === null) {
                    $currentInTarget = false;
                } else {
                    $currentInTarget = $this->isInDepartment($currentUser, $targetSlug);
                }
            }

            if (! $currentInTarget) {
                $newAssigned = $assignTo;
            } else {
                $newAssigned = $assignTo !== null ? $assignTo : $ticket->assigned_to;
            }

            $ticket->update([
                'department' => $targetSlug,
                'assigned_to' => $newAssigned,
                'status' => self::STATUS_OPEN,
            ]);

            TicketTransfer::create([
                'ticket_id' => $ticket->id,
                'from_department' => $from,
                'to_department' => $targetSlug,
                'assigned_to' => $newAssigned,
                'assigned_from' => $assignedFrom,
                'actor_id' => $actor->id,
                'note' => $note,
            ]);

            $transferNote = '[TRANSFER] '.$from.' -> '.$targetSlug.' by '.$actor->email.($note !== null && trim($note) !== '' ? ' — '.trim($note) : '');
            $this->addNote($ticket, $actor, $transferNote);

            TicketTransferred::dispatch($ticket, $from, $targetSlug, $actor);

            return $ticket->refresh();
        });
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
