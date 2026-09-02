<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outbound support-ticket email — the half of email piping that makes replies
 * possible.
 *
 * Every message carries two independent ways back to its ticket:
 *
 *  1. `[TKT-00042]` in the subject, which survives most clients and is the
 *     fallback when threading headers are stripped;
 *  2. a Message-ID we mint ourselves and store on the originating
 *     `ticket_replies` row, so an inbound `In-Reply-To` / `References` can be
 *     matched exactly even if the customer edits the subject.
 *
 * Mail leaves through the existing SendEmail job, so ticket mail lands in the
 * `emails` log next to invoices and orders.
 */
final class TicketMailService
{
    /**
     * Everything below this line is quoted history and is cut off when the
     * reply comes back. Wording matches the de-facto standard clients and
     * users already recognise; TicketMailParser keys off this exact string.
     */
    public const REPLY_MARKER = '##- Please type your reply above this line -##';

    /**
     * A bracketed token in a subject that could be a ticket number.
     *
     * Deliberately prefix-agnostic: `ticket_prefix` is an admin setting, so
     * anything bracket-wrapped and plausible is extracted and then confirmed
     * against `tickets.ticket_no` rather than assumed to be "TKT-".
     */
    private const SUBJECT_TAG_PATTERN = '/\[([A-Za-z0-9][A-Za-z0-9\-_\/]{1,30})\]/';

    /**
     * Email the customer their ticket's opening message.
     *
     * @return bool false when the ticket has no reachable customer address
     */
    public function sendCreated(Ticket $ticket): bool
    {
        $reply = $ticket->replies()->orderBy('id')->first();

        return $this->sendToCustomer($ticket, $reply, 'ticket_created');
    }

    /**
     * Email the customer a staff reply.
     *
     * @return bool false when the ticket has no reachable customer address
     */
    public function sendReply(Ticket $ticket, TicketReply $reply): bool
    {
        return $this->sendToCustomer($ticket, $reply, 'support_ticket_reply');
    }

    /**
     * Subject line carrying the ticket reference, with no double-tagging when
     * the stored subject already contains one (tickets opened from an email).
     */
    public function taggedSubject(Ticket $ticket): string
    {
        $subject = (string) $ticket->subject;
        $tag = '['.$ticket->ticket_no.']';

        return str_contains($subject, $tag) ? $subject : $tag.' '.$subject;
    }

    /**
     * Ticket numbers appearing as `[...]` tokens in a subject, most likely
     * first. Callers confirm them against the tickets table.
     *
     * @return list<string>
     */
    public static function ticketNosFromSubject(string $subject): array
    {
        if (preg_match_all(self::SUBJECT_TAG_PATTERN, $subject, $matches) === 0) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Mint a Message-ID for a ticket mail. Angle brackets are left off — the
     * value is stored bare and Symfony's id headers add them on the wire.
     */
    public function generateMessageId(Ticket $ticket): string
    {
        return sprintf('ticket-%d-%s@%s', $ticket->id, Str::lower(Str::random(20)), $this->messageIdDomain());
    }

    /**
     * Build and queue the mail, recording its Message-ID on the reply it came
     * from so a response can be threaded back.
     */
    private function sendToCustomer(Ticket $ticket, ?TicketReply $reply, string $templateName): bool
    {
        // A staff compose form may have set an explicit To (edited from the
        // default) plus Cc/Bcc; anything it left blank falls back to exactly
        // what a plain-text reply has always done. To now supports multiple
        // recipients — one email with several To addresses.
        $composedTos = $reply?->is_staff ? ($reply->to ?? null) : null;
        $effectiveTos = null;
        if (is_array($composedTos) && $composedTos !== []) {
            $filtered = array_values(array_filter(
                $composedTos,
                fn ($email) => is_string($email) && trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL)
            ));
            $filtered = array_map(fn ($e) => trim($e), $filtered);
            // Deduplicate case-insensitively, keep first casing.
            $dedup = [];
            foreach ($filtered as $address) {
                $lower = strtolower($address);
                if (! isset($dedup[$lower])) {
                    $dedup[$lower] = $address;
                }
            }
            $effectiveTos = array_values($dedup);
            if ($effectiveTos === []) {
                $effectiveTos = null;
            }
        }
        if ($effectiveTos === null) {
            $fallback = $this->recipientFor($ticket);
            $effectiveTos = $fallback ? [$fallback] : [];
        }

        if ($effectiveTos === []) {
            Log::info('Ticket email skipped: no reachable customer or guest email.', ['ticket_id' => $ticket->id]);

            return false;
        }

        $messageId = $this->generateMessageId($ticket);
        [$inReplyTo, $references] = $this->threadingFor($ticket);

        $departmentAddress = $this->departmentAddress($ticket);

        $effectiveCc = $reply?->is_staff ? (! empty($reply->cc) ? $reply->cc : $this->fallbackCcFor($ticket)) : [];
        $effectiveBcc = $reply?->is_staff ? (! empty($reply->bcc) ? $reply->bcc : $this->fallbackBccFor($ticket)) : [];

        // Never CC/BCC any primary To — deduplicate case-insensitively against all Tos.
        $toLowerArray = array_map('strtolower', $effectiveTos);
        $effectiveCc = array_values(array_filter(
            $effectiveCc,
            fn ($email) => is_string($email) && ! in_array(strtolower($email), $toLowerArray, true) && filter_var($email, FILTER_VALIDATE_EMAIL)
        ));
        // Also ensure BCC does not duplicate To or CC.
        $ccLower = array_map('strtolower', $effectiveCc);
        $effectiveBcc = array_values(array_filter(
            $effectiveBcc,
            fn ($email) => is_string($email) && ! in_array(strtolower($email), $toLowerArray, true) && ! in_array(strtolower($email), $ccLower, true) && filter_var($email, FILTER_VALIDATE_EMAIL)
        ));

        // Preserve single-string dispatch for backward compatibility with existing
        // tests and callers that assert toEmail === 'single@example.test'. Multiple
        // recipients are dispatched as an array — Mail::to handles both.
        $toForDispatch = count($effectiveTos) === 1 ? $effectiveTos[0] : $effectiveTos;

        SendEmail::dispatch(
            $toForDispatch,
            $this->taggedSubject($ticket),
            $this->body($ticket, $reply, $templateName),
            $departmentAddress,
            array_filter([
                'messageId' => $messageId,
                'inReplyTo' => $inReplyTo,
                'references' => $references,
                'replyTo' => $this->replyToAddress($ticket),
            ]),
            $effectiveCc,
            $effectiveBcc,
            $reply?->is_staff ? $reply->html_body : null,
            $reply?->is_staff ? $this->attachmentsFor($reply) : []
        );

        // Stored even though the send is queued: the id is ours, so it is
        // valid the moment it is minted, and a reply can arrive before a
        // backed-up queue drains.
        //
        // Never overwritten. On a ticket opened FROM an email, the first reply
        // already holds the customer's inbound Message-ID, which is what stops
        // that same message being imported twice. Our acknowledgement still
        // threads correctly: it carries In-Reply-To/References pointing at that
        // inbound id (see threadingFor()), so the customer's next reply cites
        // it in References and matchTicket() finds the ticket from there.
        if ($reply !== null && ($reply->email_message_id === null || $reply->email_message_id === '')) {
            $reply->forceFill([
                'email_message_id' => Str::limit($messageId, 191, ''),
            ])->save();
        }

        return true;
    }

    /**
     * Files a staff compose form attached to this reply, shaped for
     * `SendEmail`'s `$attachments` (disk path, not raw bytes).
     *
     * @return list<array{disk: string, path: string, filename: string, mimeType: ?string, isInline: bool, contentId: ?string}>
     */
    private function attachmentsFor(TicketReply $reply): array
    {
        return $reply->attachments->map(fn ($attachment) => [
            'disk' => $attachment->disk,
            'path' => $attachment->path,
            'filename' => $attachment->filename,
            'mimeType' => $attachment->mime_type,
            'isInline' => $attachment->is_inline,
            'contentId' => $attachment->content_id,
        ])->all();
    }

    /**
     * "Show original" source for a reply — the plan's Task 8.
     *
     * Inbound mail already has its real raw MIME captured
     * (`TicketMailParser`/`InboundEmail::rawSource`) — that is returned
     * as-is, verbatim, `isRaw: true`.
     *
     * A staff reply was never captured as raw MIME (it is built and handed
     * to the mailer at send time, not persisted whole), so this
     * reconstructs a representative header block from what IS stored on the
     * row — good enough to show staff what effectively went out, but
     * `isRaw: false` so the view can label it as reconstructed rather than
     * implying byte-for-byte fidelity.
     *
     * Null when there is genuinely nothing to show: a customer reply
     * submitted through the client portal or admin UI (not mail) has no raw
     * source and nothing was ever "sent" for it to reconstruct.
     *
     * @return array{source: string, isRaw: bool}|null
     */
    public function originalSourceFor(Ticket $ticket, TicketReply $reply): ?array
    {
        if ($reply->raw_source !== null && $reply->raw_source !== '') {
            return ['source' => $reply->raw_source, 'isRaw' => true];
        }

        if (! $reply->is_staff) {
            return null;
        }

        $from = $this->departmentAddress($ticket) ?: (trim((string) AppSettings::get('mail_from_address')) ?: '(default sender)');
        $to = $reply->to[0] ?? $this->recipientFor($ticket) ?? '(none)';

        $lines = [
            "From: {$from}",
            "To: {$to}",
        ];

        if ($reply->cc) {
            $lines[] = 'Cc: '.implode(', ', $reply->cc);
        }

        if ($reply->bcc) {
            $lines[] = 'Bcc: '.implode(', ', $reply->bcc);
        }

        $lines[] = 'Subject: '.$this->taggedSubject($ticket);

        if ($reply->email_message_id) {
            $lines[] = "Message-ID: <{$reply->email_message_id}>";
        }

        if ($reply->email_in_reply_to) {
            $lines[] = "In-Reply-To: <{$reply->email_in_reply_to}>";
        }

        $lines[] = '';
        $lines[] = (string) ($reply->html_body ?? $reply->message);

        return ['source' => implode("\n", $lines), 'isRaw' => false];
    }

    /**
     * Who to actually address a reply to.
     *
     * A customer's account login email and the address they actually message
     * support from are often different (a personal inbox vs. the account
     * email, a secondary contact). When the ticket has an inbound reply on
     * record, its `from_email` is what the customer is reading from RIGHT
     * NOW, so it wins over the account/guest address on file. Public so the
     * compose form can pre-fill an editable To field with the same default
     * the send path would otherwise fall back to.
     */
    public function recipientFor(Ticket $ticket): ?string
    {
        $lastInbound = $ticket->replies()
            ->where('is_staff', false)
            ->whereNotNull('from_email')
            ->latest('id')
            ->value('from_email');

        return $lastInbound ?: $ticket->display_email;
    }

    /**
     * In-Reply-To is the ticket's most recent outbound id; References carries
     * the first as well so clients keep the whole conversation in one thread.
     *
     * @return array{0: ?string, 1: list<string>}
     */
    private function threadingFor(Ticket $ticket): array
    {
        $ids = $ticket->replies()
            ->whereNotNull('email_message_id')
            ->orderBy('id')
            ->pluck('email_message_id')
            ->all();

        if ($ids === []) {
            return [null, []];
        }

        $last = (string) end($ids);
        $first = (string) reset($ids);

        return [$last, array_values(array_unique([$first, $last]))];
    }

    /**
     * Body: the admin-managed template when one is active, otherwise a plain
     * default — a missing template must never silence ticket mail. The reply
     * text itself is always appended, so the customer can answer in context.
     */
    private function body(Ticket $ticket, ?TicketReply $reply, string $templateName): string
    {
        $name = $ticket->customer?->full_name ?: 'there';
        $message = trim((string) ($reply?->message ?? ''));

        $template = EmailTemplate::query()
            ->where('name', $templateName)
            ->where('status', 'active')
            ->first();

        if ($template !== null) {
            $intro = $this->render((string) $template->body, [
                'name' => $name,
                'ticket_no' => (string) $ticket->ticket_no,
                'subject' => (string) $ticket->subject,
                'message' => $message,
            ]);
        } else {
            $intro = "Hi {$name},\n\nThere is an update on your support ticket {$ticket->ticket_no} ({$ticket->subject}).";
        }

        $parts = [$intro];

        // Only append the message when the template did not already place it,
        // otherwise the customer reads it twice.
        if ($message !== '' && ! str_contains($intro, $message)) {
            $parts[] = $message;
        }

        $parts[] = 'Reply to this email to add to the ticket.';

        $signature = $this->departmentSignature($ticket);

        if ($signature !== null) {
            $parts[] = "--\n".$signature;
        }

        $parts[] = self::REPLY_MARKER;

        return implode("\n\n", $parts);
    }

    /**
     * The department's signature, appended to outbound mail with a `--`
     * separator when the department has one configured.
     */
    private function departmentSignature(Ticket $ticket): ?string
    {
        $slug = (string) $ticket->department;

        if ($slug === '') {
            return null;
        }

        try {
            $signature = TicketDepartment::query()->where('slug', $slug)->value('signature');
        } catch (\Throwable $e) {
            return null;
        }

        $signature = trim((string) $signature);

        return $signature !== '' ? $signature : null;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function render(string $text, array $vars): string
    {
        $placeholders = array_map(fn (string $key) => '{{'.$key.'}}', array_keys($vars));

        return str_replace($placeholders, array_values($vars), $text);
    }

    /**
     * Replies must land in the mailbox the fetch command reads for THIS
     * ticket's department, so a Sales thread comes back to the Sales inbox.
     * Falls back to the global IMAP account, then the From address.
     */
    private function replyToAddress(Ticket $ticket): ?string
    {
        $candidates = [
            $this->departmentAddress($ticket),
            AppSettings::get('imap_username'),
            AppSettings::get('mail_from_address'),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The department's own address, used as the sender so a Sales ticket looks
     * like it came from Sales — the WHMCS behaviour.
     *
     * Null (keep the globally configured sender) whenever the department has
     * no address, because a From the SMTP account is not authorised for is a
     * deliverability problem, not a cosmetic one.
     */
    private function departmentAddress(Ticket $ticket): ?string
    {
        $slug = (string) $ticket->department;

        if ($slug === '') {
            return null;
        }

        try {
            $address = TicketDepartment::query()->where('slug', $slug)->value('email_address');
        } catch (\Throwable $e) {
            return null;
        }

        $address = trim((string) $address);

        return $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    /**
     * Fallback CC: distinct valid emails from all TicketReply rows for this
     * ticket where cc is not null, flattened, unique, excluding the primary To.
     *
     * @return list<string>
     */
    private function fallbackCcFor(Ticket $ticket): array
    {
        $toLower = strtolower((string) $this->recipientFor($ticket));

        $rows = $ticket->replies()->whereNotNull('cc')->get(['cc']);

        $emails = [];
        foreach ($rows as $row) {
            $cc = $row->cc;
            if (is_string($cc)) {
                $decoded = json_decode($cc, true);
                $cc = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($cc)) {
                continue;
            }
            foreach ($cc as $address) {
                if (! is_string($address)) {
                    continue;
                }
                $address = trim($address);
                if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $lower = strtolower($address);
                if ($lower === $toLower) {
                    continue;
                }
                $emails[$lower] = $address; // deduplicate case-insensitively, keep first casing
            }
        }

        return array_values($emails);
    }

    /**
     * Fallback BCC: distinct valid emails from all TicketReply rows for this
     * ticket where bcc is not null, flattened, unique, excluding the primary To.
     *
     * @return list<string>
     */
    private function fallbackBccFor(Ticket $ticket): array
    {
        $toLower = strtolower((string) $this->recipientFor($ticket));

        $rows = $ticket->replies()->whereNotNull('bcc')->get(['bcc']);

        $emails = [];
        foreach ($rows as $row) {
            $bcc = $row->bcc;
            if (is_string($bcc)) {
                $decoded = json_decode($bcc, true);
                $bcc = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($bcc)) {
                continue;
            }
            foreach ($bcc as $address) {
                if (! is_string($address)) {
                    continue;
                }
                $address = trim($address);
                if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                $lower = strtolower($address);
                if ($lower === $toLower) {
                    continue;
                }
                $emails[$lower] = $address;
            }
        }

        return array_values($emails);
    }

    /**
     * Right-hand side of the generated Message-ID: the From address domain,
     * else the app host, else a literal that is at least well-formed.
     */
    private function messageIdDomain(): string
    {
        $from = trim((string) AppSettings::get('mail_from_address'));

        if ($from !== '' && str_contains($from, '@')) {
            return Str::afterLast($from, '@');
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }
}
