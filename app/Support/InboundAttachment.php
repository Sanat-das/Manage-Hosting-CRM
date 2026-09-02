<?php

namespace App\Support;

/**
 * One attachment on a fetched message, normalised away from any particular
 * mail library — same transport-free philosophy as InboundEmail, so
 * TicketMailParser's attachment handling is testable from plain arrays with
 * no IMAP server.
 */
final class InboundAttachment
{
    public function __construct(
        public readonly string $filename,
        public readonly ?string $mimeType,
        public readonly string $content,
        public readonly bool $isInline = false,
        /** The `cid:` a message's HTML body references for an inline image, if any. */
        public readonly ?string $contentId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            filename: (string) ($data['filename'] ?? 'attachment'),
            mimeType: isset($data['mimeType']) && $data['mimeType'] !== '' ? (string) $data['mimeType'] : null,
            content: (string) ($data['content'] ?? ''),
            isInline: (bool) ($data['isInline'] ?? false),
            contentId: isset($data['contentId']) && $data['contentId'] !== '' ? (string) $data['contentId'] : null,
        );
    }
}
