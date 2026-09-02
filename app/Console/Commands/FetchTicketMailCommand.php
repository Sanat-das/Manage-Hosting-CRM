<?php

namespace App\Console\Commands;

use App\Services\TicketMailParser;
use App\Settings\EmailSettings;
use App\Support\InboundAttachment;
use App\Support\InboundEmail;
use App\Support\MailboxConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

/**
 * Poll the support mailboxes and file replies onto their tickets.
 *
 * Transport only: every decision about what a message means belongs to
 * TicketMailParser, which is why that half is testable without a mail server.
 *
 * Follows the WHMCS model of one mailbox per support department, plus the
 * global Settings > Email > Incoming Mail inbox for installs that run a single
 * address. Mailboxes are de-duplicated by host+port+user+folder before polling:
 * two configurations pointing at one inbox would import every message twice.
 *
 * A department's inbox only supplies context — which desk the mail arrived at.
 * It never overrides the department of a ticket a reply matched, so a customer
 * replying to a Support ticket from the Sales address stays on the Support
 * ticket.
 *
 * webklex/php-imap is used over ext-imap because the extension left PHP core
 * in 8.4 and is not available here; the default "imap" protocol talks IMAP
 * over sockets in pure PHP.
 */
class FetchTicketMailCommand extends Command
{
    protected $signature = 'tickets:fetch-mail
        {--limit=50 : Maximum messages to process per mailbox in one run}
        {--department= : Poll only this department slug}
        {--dry-run : Apply every rule and report, but write nothing and leave the mailbox untouched}
        {--force : Run even when the Enable Ticket Email Fetch setting is off}';

    protected $description = 'Fetch customer replies from the ticket mailboxes and add them to their tickets';

    public function handle(TicketMailParser $parser, EmailSettings $settings): int
    {
        $mailboxes = MailboxConfig::listForFetch(
            $settings,
            (string) $this->option('department'),
            (bool) $this->option('force'),
        );

        if ($mailboxes === []) {
            return $this->reportNothingToPoll($settings);
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = [];
        $failed = 0;

        foreach ($mailboxes as $mailbox) {
            $this->line("<info>{$mailbox->label}</info> ({$mailbox->username}@{$mailbox->host}:{$mailbox->port})");

            // One unreachable department must not stop the others.
            if (! $this->poll($mailbox, $parser, $dryRun, $counts)) {
                $failed++;
            }
        }

        $total = array_sum($counts);
        $summary = $counts === []
            ? 'no new messages'
            : implode(', ', array_map(fn ($status, $n) => "{$n} {$status}", array_keys($counts), $counts));

        $this->info(($dryRun ? '[dry run] ' : '')."Processed {$total} message(s) across ".count($mailboxes)." mailbox(es): {$summary}.");

        if ($failed > 0) {
            $this->error("{$failed} of ".count($mailboxes).' mailbox(es) could not be polled.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Distinguish "nothing configured" from "configured but unusable", because
     * the first is a normal state for a scheduled command and the second is an
     * error an admin has to fix.
     */
    private function reportNothingToPoll(EmailSettings $settings): int
    {
        if ($only = trim((string) $this->option('department'))) {
            $this->error("Department \"{$only}\" has no enabled mailbox of its own.");

            return self::FAILURE;
        }

        if (($settings->imap_enabled || $this->option('force')) && trim($settings->imap_host) === '') {
            $this->error('Incoming mail is enabled but no IMAP host is configured (Settings > Email > Incoming Mail).');

            return self::FAILURE;
        }

        $this->comment('No ticket mailboxes configured. Enable Incoming Mail in Settings > Email, or give a department its own mailbox.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts  accumulated across mailboxes, by reference
     * @return bool false when the mailbox could not be polled at all
     */
    private function poll(MailboxConfig $mailbox, TicketMailParser $parser, bool $dryRun, array &$counts): bool
    {
        try {
            $client = (new ClientManager)->make($mailbox->toClientConfig());
            $client->connect();
        } catch (\Throwable $e) {
            $this->error('  IMAP connection failed: '.$e->getMessage());
            Log::error('Ticket mail fetch could not connect.', [
                'mailbox' => $mailbox->label,
                'host' => $mailbox->host,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $folder = $client->getFolderByPath($mailbox->folder, soft_fail: true);

        if ($folder === null) {
            $this->error("  IMAP folder \"{$mailbox->folder}\" not found.");
            $client->disconnect();

            return false;
        }

        try {
            $messages = $folder->query()
                ->whereUnseen()
                // Flags are set explicitly per message below, only once its
                // outcome is known — a crash mid-run must not silently consume
                // mail the parser never saw.
                ->leaveUnread()
                ->setFetchBody(true)
                ->limit(max(1, (int) $this->option('limit')))
                ->get();
        } catch (\Throwable $e) {
            $this->error('  IMAP fetch failed: '.$e->getMessage());
            Log::error('Ticket mail fetch failed.', [
                'mailbox' => $mailbox->label,
                'folder' => $mailbox->folder,
                'error' => $e->getMessage(),
            ]);
            $client->disconnect();

            return false;
        }

        foreach ($messages as $message) {
            try {
                $email = $this->toInboundEmail($message);
                $result = $parser->handle($email, $dryRun, $mailbox->departmentSlug);
            } catch (\Throwable $e) {
                // One malformed message must not abort the run.
                report($e);
                $this->warn('  Skipped a message that could not be parsed: '.$e->getMessage());
                $counts['error'] = ($counts['error'] ?? 0) + 1;

                continue;
            }

            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;
            $this->line(sprintf('  [%s] %s', $result['status'], $result['reason']));

            if ($result['status'] === TicketMailParser::STATUS_UNMATCHED
                || $result['status'] === TicketMailParser::STATUS_UNKNOWN_SENDER) {
                Log::warning('Ticket mail needs a human.', [
                    'status' => $result['status'],
                    'reason' => $result['reason'],
                    'mailbox' => $mailbox->label,
                    'department' => $mailbox->departmentSlug,
                    'subject' => $email->subject,
                    'from' => $email->fromEmail,
                ]);
            }

            if (! $dryRun) {
                $this->finish($message, $result['status'], $mailbox->deleteAfterFetch);
            }
        }

        $client->disconnect();

        return true;
    }

    /**
     * Flag a processed message.
     *
     * Anything a human still has to deal with — no matching ticket, or a
     * sender we will not post as — is deliberately left UNREAD so it stays
     * visible in the mailbox instead of disappearing into an audit log.
     */
    private function finish(Message $message, string $status, bool $deleteAfterFetch): void
    {
        if (in_array($status, [TicketMailParser::STATUS_UNMATCHED, TicketMailParser::STATUS_UNKNOWN_SENDER], true)) {
            return;
        }

        try {
            if ($deleteAfterFetch) {
                $message->delete();

                return;
            }

            $message->setFlag('Seen');
        } catch (\Throwable $e) {
            // Losing the flag only means the message is seen again next run,
            // where the Message-ID dedupe catches it. Not worth failing over.
            report($e);
        }
    }

    /**
     * Normalise a webklex message into the parser's transport-free input.
     */
    private function toInboundEmail(Message $message): InboundEmail
    {
        $header = $message->getHeader();

        $body = trim($message->getTextBody());
        $htmlBody = trim((string) $message->getHTMLBody());

        if ($body === '') {
            // HTML-only mail still has to become a readable reply.
            $body = trim(html_entity_decode(strip_tags($htmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return InboundEmail::fromArray([
            'messageId' => $header->get('message_id')->toString(),
            'inReplyTo' => $header->get('in_reply_to')->toString(),
            'references' => $header->get('references')->toArray(),
            'subject' => $header->get('subject')->toString(),
            'fromEmail' => $message->getFrom()->first()?->mail ?? '',
            'fromName' => $message->getFrom()->first()?->personal ?? '',
            'body' => $body,
            'headers' => $this->rawHeaderMap($header->raw),
            'htmlBody' => $htmlBody !== '' ? $htmlBody : null,
            'rawSource' => trim($header->raw."\r\n\r\n".$message->getRawBody()),
            // Webklex\PHPIMAP\Attribute::map() already returns a plain array
            // (unlike Illuminate\Support\Collection), so no ->all() here.
            'toEmails' => $message->getTo()->map(fn ($address) => $address->mail),
            'ccEmails' => $message->getCc()->map(fn ($address) => $address->mail),
            'attachments' => $this->toInboundAttachments($message),
        ]);
    }

    /**
     * Normalise webklex attachments away from the library, same as the rest
     * of toInboundEmail() — TicketMailParser never touches a webklex object.
     *
     * @return list<InboundAttachment>
     */
    private function toInboundAttachments(Message $message): array
    {
        return $message->getAttachments()
            ->map(fn ($attachment) => new InboundAttachment(
                filename: $attachment->filename ?: $attachment->name ?: 'attachment',
                mimeType: $attachment->getMimeType(),
                content: $attachment->getContent(),
                isInline: strtolower((string) $attachment->disposition) === 'inline',
                contentId: $attachment->id,
            ))
            ->values()
            ->all();
    }

    /**
     * Flatten the raw header block to `name => value`.
     *
     * Parsed straight from the raw text rather than webklex's attribute bag
     * because the loop guards need exact RFC names (`auto-submitted`,
     * `list-id`), which the library rewrites to underscored keys.
     *
     * @return array<string, string>
     */
    private function rawHeaderMap(string $raw): array
    {
        $headers = [];
        // Unfold continuation lines first, or "Auto-Submitted:\r\n auto-replied"
        // would be read as an empty value.
        $normalised = preg_replace("/\r\n[ \t]+/", ' ', $raw);

        foreach (preg_split("/\r\n|\n/", (string) $normalised) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));

            if ($name === '' || str_contains($name, ' ')) {
                continue;
            }

            // First occurrence wins, matching how a receiving MTA reads them.
            $headers[$name] ??= trim($value);
        }

        return $headers;
    }
}
