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
use Illuminate\Support\Facades\Log;
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

    /**
     * New tickets one sender may open per hour before the flood guard stops
     * believing them. Overridable via the `imap_max_new_tickets_per_hour`
     * setting; high enough that a genuinely busy customer is never caught.
     */
    private const DEFAULT_NEW_TICKETS_PER_HOUR = 20;

    /**
     * Lines that may follow a `-- ` marker and still be read as a signature
     * rather than as the customer continuing to write.
     */
    private const MAX_SIGNATURE_LINES = 10;

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

        if ($reason = $this->selfLoopReason($email)) {
            return $this->result(self::STATUS_IGNORED, $reason);
        }

        // Mail without a Message-ID used to skip the duplicate check entirely,
        // so a message whose Seen flag failed to stick was re-imported on every
        // poll. A fingerprint of the parts that identify the message stands in:
        // stable across re-fetches of the SAME mail (Date is a header, not a
        // receive time) but different for a genuine second message.
        $messageId = $email->messageId !== '' ? $email->messageId : $this->fingerprintMessageId($email);

        if ($this->alreadyProcessed($messageId)) {
            return $this->result(self::STATUS_DUPLICATE, 'Message-ID already recorded on a reply.');
        }

        $ticket = $this->matchTicket($email);

        if ($ticket === null) {
            return $this->openTicket($email, $dryRun, $mailboxDepartment, $messageId);
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

        return DB::transaction(function () use ($ticket, $author, $message, $email, $messageId) {
            $reply = $this->tickets->reply($ticket, $author, $message);

            $reply->forceFill([
                'email_message_id' => Str::limit($messageId, 191, ''),
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
    private function openTicket(InboundEmail $email, bool $dryRun, ?string $mailboxDepartment, string $messageId): array
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

        if ($reason = $this->floodReason($email)) {
            return $this->result(self::STATUS_IGNORED, $reason);
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

        return DB::transaction(function () use ($email, $department, $customer, $isGuest, $message, $messageId) {
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
                'email_message_id' => Str::limit($messageId, 191, ''),
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

        $fallback = TicketDepartment::query()->enabled()->ordered()->first();

        if ($fallback !== null) {
            Log::warning('TicketMailParser department fallback used — no default department; picked first enabled.', [
                'fallback_department' => $fallback->slug,
                'fallback_name' => $fallback->name,
                'requested_mailbox_department' => $mailboxDepartment,
            ]);
        }

        return $fallback;
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
     *
     * Handles both top-posted (reply before quote, common) and bottom-posted
     * (reply after quote, e.g. "On ... wrote:\n> quote\nThis is a reply")
     * clients: if nothing survives before the first quote header, the text
     * after the quoted block is used instead.
     */
    public function stripQuotedText(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        // The marker ends OUR trailer — nothing we send follows it — so the two
        // sides are read separately rather than the second being thrown away.
        //
        // This used to `substr($body, 0, $markerAt)` before anything else, which
        // destroyed the reply of every customer who typed UNDER the quoted
        // history: their words live after the marker, the text before it is all
        // quoted, and the result was an empty body. An empty body is dropped
        // (STATUS_EMPTY) and the mail is flagged Seen, so the reply vanished
        // with nothing to show for it. It looked intermittent because it only
        // hit customers whose client bottom-posts.
        $beforeMarker = $body;
        $afterMarker = '';

        if (($markerAt = strpos($body, TicketMailService::REPLY_MARKER)) !== false) {
            $beforeMarker = substr($body, 0, $markerAt);
            $afterMarker = substr($body, $markerAt + strlen(TicketMailService::REPLY_MARKER));
        }

        // Top-posted: what they wrote sits above the history. The common case.
        $topPosted = $this->textBeforeQuotedHistory($beforeMarker);

        if ($topPosted !== '') {
            return $topPosted;
        }

        // Bottom-posted: nothing above the history, so take what is unquoted
        // below our trailer.
        $bottomPosted = $this->textOutsideQuotedHistory($afterMarker);

        if ($bottomPosted !== '') {
            return $bottomPosted;
        }

        // Neither side yielded anything: the reply may be interleaved through
        // the quoted block, so keep everything that is not quoted history.
        return $this->textOutsideQuotedHistory($body);
    }

    /**
     * Everything up to the first quoted-history opener — a top-posted reply.
     */
    private function textBeforeQuotedHistory(string $body): string
    {
        $kept = [];

        foreach (explode("\n", $body) as $line) {
            if ($this->isQuoteHeader(trim($line))) {
                break;
            }

            $kept[] = $line;
        }

        $kept = $this->dropTrailingSignature($kept);

        // Drop any quoted block left dangling at the end.
        while ($kept !== [] && (trim((string) end($kept)) === '' || str_starts_with(trim((string) end($kept)), '>'))) {
            array_pop($kept);
        }

        return trim(implode("\n", $kept));
    }

    /**
     * Everything that is not quoted history — used for the bottom-posted and
     * interleaved shapes, where there is no single clean cut point.
     */
    private function textOutsideQuotedHistory(string $body): string
    {
        $kept = [];

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            if ($this->isQuoteHeader($trimmed)
                || str_starts_with($trimmed, '>')
                || str_contains($line, TicketMailService::REPLY_MARKER)) {
                continue;
            }

            $kept[] = $line;
        }

        // Same signature rule as the top-posted path — a `-- ` in the middle of
        // what the customer wrote is a separator, not the end of the message.
        $kept = $this->dropTrailingSignature($kept);

        while ($kept !== [] && trim((string) end($kept)) === '') {
            array_pop($kept);
        }

        while ($kept !== [] && trim((string) $kept[0]) === '') {
            array_shift($kept);
        }

        return trim(implode("\n", $kept));
    }

    /**
     * A line that opens the quoted history of the message being replied to.
     */
    private function isQuoteHeader(string $trimmed): bool
    {
        return preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $trimmed) === 1
            || preg_match('/^_{10,}$/', $trimmed) === 1
            || preg_match('/^On .{0,200}\bwrote:$/i', $trimmed) === 1;
    }

    /**
     * Drop an RFC 3676 `-- ` signature block, but only when it really is one.
     *
     * The main loop used to `break` at the first `-- ` line, which silently
     * truncated every message where a customer used a dash rule as a separator
     * — everything they wrote below it was thrown away. A signature is the
     * LAST such marker and has only a few lines after it; anything longer is
     * the customer still talking.
     *
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function dropTrailingSignature(array $lines): array
    {
        $markerAt = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^-- $/', $line)) {
                $markerAt = $index;
            }
        }

        if ($markerAt === null) {
            return $lines;
        }

        $following = array_slice($lines, $markerAt + 1);

        // Trailing blanks do not make a signature look longer than it is.
        while ($following !== [] && trim((string) end($following)) === '') {
            array_pop($following);
        }

        return count($following) <= self::MAX_SIGNATURE_LINES
            ? array_slice($lines, 0, $markerAt)
            : $lines;
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
     * A stand-in Message-ID for mail that arrived without one.
     *
     * Built from the headers that identify the message rather than from when
     * we happened to read it, so re-polling the same mail produces the same id
     * and `alreadyProcessed()` catches it — while a genuine second message
     * (different Date, or different text) gets its own. Shaped like a real
     * Message-ID because it is stored in the same column and can end up in an
     * outbound References header.
     */
    private function fingerprintMessageId(InboundEmail $email): string
    {
        $fingerprint = sha1(implode("\n", [
            strtolower(trim($email->fromEmail)),
            trim($email->subject),
            trim((string) $email->header('date')),
            trim($email->body),
        ]));

        return 'no-message-id-'.$fingerprint.'@'.parse_url((string) config('app.url'), PHP_URL_HOST).'';
    }

    /**
     * Why this sender is opening too many tickets to keep believing them.
     *
     * Only reached from {@see self::openTicket()}, so a reply to an existing
     * ticket is never throttled — this caps NEW tickets, and with them the
     * user/customer rows auto-registration would create. Without it a mail
     * flood turns into an unbounded write loop: one ticket, one user and one
     * customer per message, every five minutes, forever.
     */
    private function floodReason(InboundEmail $email): ?string
    {
        $from = strtolower(trim($email->fromEmail));

        if ($from === '') {
            return null;
        }

        $cap = max(1, (int) (AppSettings::get('imap_max_new_tickets_per_hour') ?? self::DEFAULT_NEW_TICKETS_PER_HOUR));

        $recent = Ticket::query()
            ->where('created_at', '>=', now()->subHour())
            ->whereHas('replies', fn ($query) => $query->whereRaw('LOWER(from_email) = ?', [$from]))
            ->count();

        if ($recent < $cap) {
            return null;
        }

        Log::warning('Ticket mail flood guard tripped — sender is opening tickets faster than the cap allows.', [
            'from' => $email->fromEmail,
            'opened_last_hour' => $recent,
            'cap' => $cap,
        ]);

        return "Flood guard: {$email->fromEmail} has opened {$recent} tickets in the last hour (cap {$cap}) — left for a human.";
    }

    /**
     * Guard against mail loops: if the From address matches any configured
     * department or global mailbox username, this is our own outbound being
     * read back (e.g. BCC to self, inbox copy). Dropping it prevents
     * tickets:fetch-mail from re-importing ticket replies we just sent via
     * queue:work --queue=emails.
     */
    private function selfLoopReason(InboundEmail $email): ?string
    {
        $from = strtolower(trim($email->fromEmail));
        if ($from === '') {
            return null;
        }

        foreach ($this->knownMailboxUsernames() as $mailboxUser) {
            if (strtolower(trim($mailboxUser)) === $from) {
                return "Self-loop guard: From {$email->fromEmail} matches configured mailbox {$mailboxUser} — own outbound, ignored.";
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function knownMailboxUsernames(): array
    {
        try {
            $usernames = [];

            // Also cover the From address — outbound ticket mail uses
            // department email as From and may match From header on loopback.
            $fromAddress = trim((string) AppSettings::get('mail_from_address'));
            if ($fromAddress !== '') {
                $usernames[] = $fromAddress;
            }

            $deptUsernames = TicketDepartment::query()
                ->enabled()
                ->whereNotNull('email_address')
                ->where('email_address', '!=', '')
                ->pluck('email_address')
                ->all();

            foreach ($deptUsernames as $addr) {
                $addr = trim((string) $addr);
                if ($addr !== '') {
                    $usernames[] = $addr;
                }
            }

            // Department IMAP usernames may differ from email_address (login vs sender).
            $deptImapUsers = TicketDepartment::query()
                ->enabled()
                ->whereNotNull('imap_username')
                ->where('imap_username', '!=', '')
                ->pluck('imap_username')
                ->all();

            foreach ($deptImapUsers as $u) {
                $u = trim((string) $u);
                if ($u !== '') {
                    $usernames[] = $u;
                }
            }

            return array_values(array_unique(array_map('strtolower', $usernames)));
        } catch (\Throwable) {
            return [];
        }
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

        // Guest ticket: the From address that opened it (guest_email) is the owner.
        // Allow even without a User row — synthesize a client user so the reply
        // is stored as a customer reply (is_staff false, user_id null if no account).
        if ($ticket->isGuest() && $emailLower !== '' && strtolower(trim((string) $ticket->guest_email)) === $emailLower) {
            if ($user !== null) {
                return $user;
            }

            return new User(['email' => $email->fromEmail, 'role' => 'client']);
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
