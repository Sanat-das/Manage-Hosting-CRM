<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\InboundEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns a fetched email into a ticket reply — the inbound half of email piping.
 *
 * Deliberately transport-free: it takes an InboundEmail and returns a status,
 * so every matching, authorisation and hygiene rule can be tested without an
 * IMAP server. FetchTicketMailCommand supplies the messages.
 *
 * Order of business for each message: drop machine mail, drop anything already
 * processed, find the ticket, prove the sender is entitled to post to it, then
 * strip the quoted history and file the reply through TicketService so status
 * transitions and notifications behave exactly as an in-app reply.
 */
final class TicketMailParser
{
    public const STATUS_CREATED = 'created';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_UNKNOWN_SENDER = 'unknown_sender';

    public const STATUS_EMPTY = 'empty';

    /** Dry run only: every rule passed, the reply was simply not written. */
    public const STATUS_WOULD_CREATE = 'would_create';

    /** Mail matched no ticket and opened a new one. */
    public const STATUS_TICKET_OPENED = 'ticket_opened';

    /** Dry run only: a new ticket would have been opened. */
    public const STATUS_WOULD_OPEN_TICKET = 'would_open_ticket';

    /**
     * Header patterns that mark machine-generated mail. Replying to any of
     * these risks a loop: our reply notification triggers their autoresponder,
     * which triggers ours, forever.
     *
     * @var array<string, string> header name => regex its value must NOT match
     */
    private const AUTOMATED_HEADERS = [
        'auto-submitted' => '/^(?!no\b).+/i',
        'precedence' => '/^(bulk|list|junk|auto_reply)/i',
        'x-autoreply' => '/.+/',
        'x-autorespond' => '/.+/',
        'x-auto-response-suppress' => '/.+/',
        'list-id' => '/.+/',
        'list-unsubscribe' => '/.+/',
        'x-failed-recipients' => '/.+/',
    ];

    /** Local-parts that never represent a human worth threading a reply from. */
    private const ROBOT_LOCAL_PARTS = [
        'mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'donotreply', 'do-not-reply', 'bounce', 'bounces',
    ];

    public function __construct(private readonly TicketService $tickets) {}

    /**
     * Department transfer is a staff-initiated action only (TicketService::transferDepartment).
     * An inbound mail that matches an existing ticket NEVER moves it — the ticket
     * keeps whatever department a human last put it in, regardless of which
     * mailbox (global or per-department) the reply arrived on.
     *
     * @param  bool  $dryRun  run every rule but write nothing — how an operator
     *                        proves a new mailbox behaves before going live
     * @param  string|null  $mailboxDepartment  slug of the department whose mailbox this
     *                                          arrived in; only ever used to place a NEW
     *                                          ticket, never to move an existing one
     * @return array{status: string, reason: string, reply: ?TicketReply, ticket: ?Ticket}
     */
    public function handle(InboundEmail $email, bool $dryRun = false, ?string $mailboxDepartment = null): array
    {
        if ($reason = $this->automatedReason($email)) {
            return $this->result(self::STATUS_IGNORED, $reason);
        }

        if ($email->messageId !== '' && $this->alreadyProcessed($email->messageId)) {
            return $this->result(self::STATUS_DUPLICATE, 'Message-ID already recorded on a reply.');
        }

        $ticket = $this->matchTicket($email);

        if ($ticket === null) {
            return $this->openTicket($email, $dryRun, $mailboxDepartment);
        }

        $author = $this->authorFor($ticket, $email);

        if ($author === null) {
            return $this->result(
                self::STATUS_UNKNOWN_SENDER,
                "Sender {$email->fromEmail} is neither the ticket's customer nor staff.",
                null,
                $ticket
            );
        }

        $message = $this->stripQuotedText($email->body);

        if ($message === '') {
            return $this->result(self::STATUS_EMPTY, 'Nothing left after stripping quoted history.', null, $ticket);
        }

        if ($dryRun) {
            return $this->result(
                self::STATUS_WOULD_CREATE,
                "Would add a reply to {$ticket->ticket_no} as {$author->email}",
                null,
                $ticket
            );
        }

        // A customer answering a closed ticket means it is not resolved after
        // all. Reopening beats silently dropping their mail.
        if ($ticket->status === TicketService::STATUS_CLOSED) {
            $this->tickets->reopen($ticket);
            $ticket->refresh();
        }

        return DB::transaction(function () use ($ticket, $author, $message, $email) {
            $reply = $this->tickets->reply($ticket, $author, $message);

            $reply->forceFill([
                'email_message_id' => $email->messageId !== '' ? Str::limit($email->messageId, 191, '') : null,
                'email_in_reply_to' => $email->inReplyTo !== null ? Str::limit($email->inReplyTo, 191, '') : null,
                'from_email' => $email->fromEmail !== '' ? Str::limit($email->fromEmail, 191, '') : null,
                'html_body' => $email->htmlBody,
                'raw_source' => $email->rawSource,
                'to' => $email->toEmails !== [] ? $email->toEmails : null,
                'cc' => $email->ccEmails !== [] ? $email->ccEmails : null,
            ])->save();

            $this->storeAttachments($reply, $email);

            return $this->result(self::STATUS_CREATED, 'Reply added to '.$ticket->ticket_no, $reply, $ticket);
        });
    }

    /**
     * Mail that matched no ticket: open one at the desk it was addressed to.
     *
     * This path — and only this path — may register the sender. A message that
     * DID match a ticket never reaches here, so auto-creation can never be used
     * to manufacture an author for someone else's ticket; authorFor() stays
     * strict for replies.
     *
     * @return array{status: string, reason: string, reply: ?TicketReply, ticket: ?Ticket}
     */
    private function openTicket(InboundEmail $email, bool $dryRun, ?string $mailboxDepartment): array
    {
        $department = $this->departmentFor($mailboxDepartment);

        if ($department === null) {
            return $this->result(self::STATUS_UNMATCHED, 'No ticket matched, and no department is available to open one in.');
        }

        if (! $department->allow_new_tickets) {
            return $this->result(
                self::STATUS_UNMATCHED,
                "No ticket matched, and {$department->name} does not accept new tickets by email."
            );
        }

        $message = $this->stripQuotedText($email->body);

        if ($message === '') {
            return $this->result(self::STATUS_EMPTY, 'Nothing left after stripping quoted history.');
        }

        $customer = $this->customerFor($email, $dryRun);

        // Guest handling: when no customer found and auto-create is off, create as guest ticket instead of holding for review
        $isGuest = $customer === null;

        if ($dryRun) {
            if ($isGuest) {
                return $this->result(
                    self::STATUS_WOULD_OPEN_TICKET,
                    "Would open a {$department->name} guest ticket for {$email->fromEmail} (unknown sender)"
                );
            }
            return $this->result(
                self::STATUS_WOULD_OPEN_TICKET,
                "Would open a {$department->name} ticket for {$email->fromEmail}"
            );
        }

        return DB::transaction(function () use ($email, $department, $customer, $isGuest, $message) {
            $ticketData = [
                'subject' => $this->subjectFor($email),
                'department' => $department->slug,
                'priority' => 'medium',
            ];
            if ($isGuest) {
                $ticketData['guest_email'] = $email->fromEmail;
                $ticketData['guest_name'] = $email->senderName();
            } else {
                $ticketData['customer_id'] = $customer->id;
            }

            $ticket = $this->tickets->create($ticketData, $message, [
                'email_message_id' => $email->messageId !== '' ? Str::limit($email->messageId, 191, '') : null,
                'email_in_reply_to' => $email->inReplyTo !== null ? Str::limit($email->inReplyTo, 191, '') : null,
                'from_email' => $email->fromEmail !== '' ? Str::limit($email->fromEmail, 191, '') : null,
                'html_body' => $email->htmlBody,
                'raw_source' => $email->rawSource,
                'to' => $email->toEmails !== [] ? $email->toEmails : null,
                'cc' => $email->ccEmails !== [] ? $email->ccEmails : null,
            ]);

            $this->storeAttachments($ticket->replies()->orderBy('id')->first(), $email);

            $statusMsg = $isGuest
                ? "Opened guest ticket {$ticket->ticket_no} in {$department->name} for {$email->fromEmail} (unknown user)"
                : "Opened {$ticket->ticket_no} in {$department->name} for {$email->fromEmail}";

            return $this->result(
                self::STATUS_TICKET_OPENED,
                $statusMsg,
                null,
                $ticket
            );
        });
    }

    /**
     * Which desk a NEW ticket belongs to: the mailbox it arrived in, else the
     * configured default for the shared mailbox, else the explicit is_default
     * department, else the first enabled department so a working install never
     * silently drops mail.
     *
     * Only called from openTicket() — a mail that matched an existing ticket
     * never reaches this method, so it can never be used to relocate one.
     * Department transfer is staff-initiated only, via
     * TicketService::transferDepartment.
     */
    private function departmentFor(?string $mailboxDepartment): ?TicketDepartment
    {
        $slug = trim((string) $mailboxDepartment);

        if ($slug === '') {
            $slug = trim((string) AppSettings::get('imap_default_department'));
        }

        if ($slug !== '') {
            $department = TicketDepartment::query()->enabled()->where('slug', $slug)->first();

            if ($department !== null) {
                return $department;
            }
        }

        $default = TicketDepartment::query()->enabled()->where('is_default', true)->first();

        if ($default !== null) {
            return $default;
        }

        return TicketDepartment::query()->enabled()->ordered()->first();
    }

    /**
     * The customer this mail belongs to, registering the sender when allowed.
     *
     * Returns null when the sender is unknown and auto-registration is off —
     * the "hold for review" outcome, which leaves the message unread in the
     * mailbox for a human.
     */
    private function customerFor(InboundEmail $email, bool $dryRun): ?Customer
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $email->fromEmail)])->first();

        if ($user !== null) {
            $customer = Customer::query()->where('user_id', $user->id)->first();

            if ($customer !== null) {
                return $customer;
            }

            // A known login with no customer record — staff, most likely. There
            // is no ticket to infer a customer from, so a human decides.
            return null;
        }

        // Second chance: resolve via customer_contacts.email (case-insensitive)
        // e.g. sanat.das85@gmail.com as a contact of Client1 User → that customer.
        $contact = CustomerContact::query()
            ->whereRaw('LOWER(email) = ?', [strtolower((string) $email->fromEmail)])
            ->with('customer')
            ->first();

        if ($contact !== null && $contact->customer !== null) {
            return $contact->customer;
        }

        if (! AppSettings::bool('imap_auto_create_customers', true)) {
            return null;
        }

        if ($dryRun) {
            // Stand-in so the dry run can report "would open" without writing.
            return new Customer;
        }

        return DB::transaction(function () use ($email) {
            // This schema splits the name and stores the hash in
            // `password_hash`, not Laravel's default `password`.
            [$firstName, $lastName] = $this->splitName($email->senderName());

            $user = User::create([
                'email' => $email->fromEmail,
                // Never a usable password: the account is reachable only through
                // the normal password-reset flow.
                'password_hash' => Hash::make(Str::random(40)),
                'role' => 'client',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'status' => 'active',
            ]);

            return Customer::create([
                'user_id' => $user->id,
                'status' => 'active',
            ]);
        });
    }

    /**
     * Split a display name across this schema's first_name / last_name.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [
            Str::limit($parts[0] ?? '', 100, ''),
            Str::limit($parts[1] ?? '', 100, ''),
        ];
    }

    /**
     * Subject for a mail-opened ticket, with reply/forward prefixes stripped so
     * the ticket is not called "Re: Fwd: Re: help".
     */
    private function subjectFor(InboundEmail $email): string
    {
        $subject = trim($email->subject);

        do {
            $previous = $subject;
            $subject = trim((string) preg_replace('/^\s*(re|fwd?|aw|antw|sv|vs|rif)\s*(\[\d+\])?\s*:\s*/i', '', $subject));
        } while ($subject !== $previous && $subject !== '');

        return Str::limit($subject !== '' ? $subject : 'No subject', 250, '');
    }

    /**
     * Cut quoted history off a reply body.
     *
     * Conservative by design: an over-eager stripper silently truncates real
     * customer text, which is worse than leaving a few quoted lines behind.
     * Only the marker we ship and the two near-universal quote openers cut the
     * body; beyond that just trailing `>` blocks and signatures are dropped.
     */
    public function stripQuotedText(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // Our own marker is authoritative when present.
        if (($markerAt = strpos($body, TicketMailService::REPLY_MARKER)) !== false) {
            $body = substr($body, 0, $markerAt);
        }

        $lines = explode("\n", $body);
        $kept = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $trimmed)
                || preg_match('/^_{10,}$/', $trimmed)
                || preg_match('/^On .{0,200}\bwrote:$/i', $trimmed)
                || preg_match('/^-- $/', $line)) {
                break;
            }

            $kept[] = $line;
        }

        // Drop any quoted block left dangling at the end.
        while ($kept !== [] && (trim((string) end($kept)) === '' || str_starts_with(trim((string) end($kept)), '>'))) {
            array_pop($kept);
        }

        return trim(implode("\n", $kept));
    }

    /**
     * Why this message must not be answered, or null when it looks human.
     */
    private function automatedReason(InboundEmail $email): ?string
    {
        foreach (self::AUTOMATED_HEADERS as $header => $pattern) {
            $value = trim((string) $email->header($header));

            if ($value !== '' && preg_match($pattern, $value) === 1) {
                return "Automated mail: {$header}: {$value}";
            }
        }

        $localPart = Str::before($email->fromEmail, '@');

        if ($localPart !== '' && in_array($localPart, self::ROBOT_LOCAL_PARTS, true)) {
            return "Automated sender: {$email->fromEmail}";
        }

        if ($email->fromEmail === '') {
            return 'Message has no usable From address.';
        }

        return null;
    }

    private function alreadyProcessed(string $messageId): bool
    {
        return TicketReply::query()
            ->where('email_message_id', Str::limit($messageId, 191, ''))
            ->exists();
    }

    /**
     * Persist a reply's inbound attachments to disk and one
     * `ticket_attachments` row each.
     *
     * `basename()` on the filename is not decorative — a mail client
     * controls what it puts in the filename header, and joining it into a
     * storage path unguarded would let a crafted `../../` walk outside the
     * ticket's attachment directory.
     */
    private function storeAttachments(TicketReply $reply, InboundEmail $email): void
    {
        if ($email->attachments === []) {
            return;
        }

        $disk = Storage::disk('local');

        foreach ($email->attachments as $index => $attachment) {
            $safeName = basename($attachment->filename) ?: "attachment-{$index}";
            $path = "ticket-attachments/{$reply->ticket_id}/{$reply->id}/{$index}-{$safeName}";

            $disk->put($path, $attachment->content);

            TicketAttachment::create([
                'ticket_reply_id' => $reply->id,
                'disk' => 'local',
                'path' => $path,
                'filename' => $safeName,
                'mime_type' => $attachment->mimeType,
                'size_bytes' => strlen($attachment->content),
                'is_inline' => $attachment->isInline,
                'content_id' => $attachment->contentId,
            ]);
        }
    }

    /**
     * Thread headers first — they survive subject edits and translated "Re:"
     * prefixes — then the `[TKT-…]` subject tag as the fallback for clients
     * that drop References.
     */
    private function matchTicket(InboundEmail $email): ?Ticket
    {
        foreach ($email->threadIds() as $id) {
            $reply = TicketReply::query()
                ->where('email_message_id', Str::limit($id, 191, ''))
                ->latest('id')
                ->first();

            if ($reply?->ticket !== null) {
                return $reply->ticket;
            }
        }

        foreach (TicketMailService::ticketNosFromSubject($email->subject) as $ticketNo) {
            $ticket = Ticket::query()->where('ticket_no', $ticketNo)->first();

            if ($ticket !== null) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Who this mail may post as.
     *
     * From is trivially forgeable, so it only ever authorises a sender against
     * the ticket they are writing to: the ticket's own customer, or a staff
     * account. Anything else is rejected rather than silently attributed —
     * otherwise anyone knowing a ticket number could post into it.
     */
    private function authorFor(Ticket $ticket, InboundEmail $email): ?User
    {
        $emailLower = strtolower((string) $email->fromEmail);

        $user = User::query()->whereRaw('LOWER(email) = ?', [$emailLower])->first();

        if ($user !== null && $ticket->customer?->user_id === $user->id) {
            return $user;
        }

        // Contact fallback: sender matches a CustomerContact belonging to the
        // ticket's customer → authorize as that customer's user (case-insensitive).
        // Guest tickets (no customer) never authorize via contacts.
        if ($ticket->customer_id !== null && $ticket->customer !== null && $ticket->customer->user !== null) {
            $isContact = CustomerContact::where('customer_id', $ticket->customer_id)
                ->whereRaw('LOWER(email) = ?', [$emailLower])
                ->exists();

            if ($isContact) {
                return $ticket->customer->user;
            }
        }

        if ($user !== null && $this->tickets->isStaff($user)) {
            return $user;
        }

        return null;
    }

    /**
     * @return array{status: string, reason: string, reply: ?TicketReply, ticket: ?Ticket}
     */
    private function result(string $status, string $reason, ?TicketReply $reply = null, ?Ticket $ticket = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'reply' => $reply, 'ticket' => $ticket];
    }
}
