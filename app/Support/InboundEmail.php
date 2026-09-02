<?php

namespace App\Support;

/**
 * One fetched message, normalised away from any particular mail library.
 *
 * TicketMailParser works on this and nothing else, so the whole matching and
 * hygiene story is testable from plain arrays — no IMAP server, no fixtures
 * that depend on webklex internals.
 *
 * Ids are held WITHOUT angle brackets, matching how they are stored on
 * ticket_replies and how webklex's Header parser hands them over.
 */
final class InboundEmail
{
    /**
     * @param  list<string>  $references
     * @param  array<string, string>  $headers  lower-cased header name => value,
     *                                          used only for the auto-reply guards
     * @param  list<string>  $toEmails  lower-cased addresses from the To header
     * @param  list<string>  $ccEmails  lower-cased addresses from the Cc header
     * @param  list<InboundAttachment>  $attachments
     */
    public function __construct(
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly string $subject,
        public readonly string $fromEmail,
        public readonly string $body,
        public readonly array $headers = [],
        /** Display name from the From header, used to name an auto-created customer. */
        public readonly string $fromName = '',
        /** Original HTML body, when the message had one. Presentation-only — matching/quoting stays on $body. */
        public readonly ?string $htmlBody = null,
        /** Full raw message (headers + body) as fetched, for the "show original" view. */
        public readonly ?string $rawSource = null,
        public readonly array $toEmails = [],
        public readonly array $ccEmails = [],
        public readonly array $attachments = [],
    ) {}

    /**
     * Best available human name for the sender: the From display name, else
     * the local part tidied up ("jane.doe" => "Jane Doe").
     */
    public function senderName(): string
    {
        $name = trim($this->fromName);

        if ($name !== '') {
            return $name;
        }

        $localPart = str_replace(['.', '_', '-', '+'], ' ', strstr($this->fromEmail, '@', true) ?: $this->fromEmail);

        return ucwords(trim($localPart)) ?: $this->fromEmail;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: self::stripBrackets((string) ($data['messageId'] ?? '')),
            inReplyTo: isset($data['inReplyTo']) && $data['inReplyTo'] !== null && $data['inReplyTo'] !== ''
                ? self::stripBrackets((string) $data['inReplyTo'])
                : null,
            references: array_values(array_filter(array_map(
                fn ($id) => self::stripBrackets((string) $id),
                (array) ($data['references'] ?? [])
            ))),
            subject: (string) ($data['subject'] ?? ''),
            fromEmail: strtolower(trim((string) ($data['fromEmail'] ?? ''))),
            body: (string) ($data['body'] ?? ''),
            headers: array_change_key_case(array_map(
                fn ($v) => is_array($v) ? implode(' ', $v) : (string) $v,
                (array) ($data['headers'] ?? [])
            ), CASE_LOWER),
            fromName: trim((string) ($data['fromName'] ?? '')),
            htmlBody: isset($data['htmlBody']) && $data['htmlBody'] !== '' ? (string) $data['htmlBody'] : null,
            rawSource: isset($data['rawSource']) && $data['rawSource'] !== '' ? (string) $data['rawSource'] : null,
            toEmails: self::normaliseAddresses((array) ($data['toEmails'] ?? [])),
            ccEmails: self::normaliseAddresses((array) ($data['ccEmails'] ?? [])),
            attachments: array_map(
                fn ($a) => $a instanceof InboundAttachment ? $a : InboundAttachment::fromArray((array) $a),
                (array) ($data['attachments'] ?? [])
            ),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Candidate ids to match a ticket on, most specific first: what this mail
     * directly answers, then the thread's ancestry newest-first.
     *
     * @return list<string>
     */
    public function threadIds(): array
    {
        $ids = [];

        if ($this->inReplyTo !== null) {
            $ids[] = $this->inReplyTo;
        }

        foreach (array_reverse($this->references) as $reference) {
            $ids[] = $reference;
        }

        return array_values(array_unique($ids));
    }

    private static function stripBrackets(string $id): string
    {
        return trim(str_replace(['<', '>'], '', $id));
    }

    /**
     * @param  array<mixed>  $addresses
     * @return list<string>
     */
    private static function normaliseAddresses(array $addresses): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($a) => strtolower(trim((string) $a)),
            $addresses
        ))));
    }
}
