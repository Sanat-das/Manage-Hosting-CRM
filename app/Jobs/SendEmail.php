<?php

namespace App\Jobs;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Send an email asynchronously and log the result.
 */
class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $headers  optional RFC 5322 threading headers:
     *                                         `messageId`, `inReplyTo`, `references` (string|list<string>),
     *                                         `replyTo`. Ids are passed WITHOUT angle brackets — Symfony's
     *                                         id headers add them. Ticket mail sets these so a customer's
     *                                         reply can be threaded back onto the right ticket; every other
     *                                         caller omits them and behaves exactly as before.
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  ?string  $htmlBody  when set, sent as the HTML part instead of `Mail::raw()`'s
     *                             plain text — `$body` is still stored on the EmailLog row and
     *                             sent as the plain-text alternative part.
     * @param  list<array{disk: string, path: string, filename: string, mimeType: ?string, isInline: bool, contentId: ?string}>  $attachments
     *                             file paths on disk, not raw bytes — keeps queued job payloads small.
     *                             `isInline`+`contentId` embed the file for a `cid:` reference in `$htmlBody`
     *                             instead of listing it as a downloadable attachment.
     */
    /**
     * @param string|list<string> $toEmail
     */
    public function __construct(
        public string|array $toEmail,
        public string $subject,
        public string $body,
        public ?string $fromEmail = null,
        public array $headers = [],
        public array $cc = [],
        public array $bcc = [],
        public ?string $htmlBody = null,
        public array $attachments = [],
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $log = EmailLog::create([
            'to_email' => is_array($this->toEmail) ? implode(', ', $this->toEmail) : $this->toEmail,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => 'queued',
        ]);

        try {
            if ($this->htmlBody !== null || $this->attachments !== []) {
                $this->sendRich();
            } else {
                // Use raw mail (no Mailable class needed for now)
                Mail::raw($this->body, function ($message) {
                    $message->to($this->toEmail)
                        ->subject($this->subject);

                    if ($this->fromEmail) {
                        $message->from($this->fromEmail);
                    }

                    $this->applyHeaders($message);
                });
            }

            $log->update(['status' => 'sent']);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * HTML body and/or attachments: `Mail::raw()` cannot carry either, so this
     * builds the message directly via `Mail::send()` with an inline HTML view
     * plus the plain-text alternative, same threading/Cc/Bcc/from handling as
     * the plain path.
     */
    private function sendRich(): void
    {
        Mail::send([], [], function ($message) {
            $message->to($this->toEmail)
                ->subject($this->subject);

            if ($this->cc !== []) {
                $message->cc($this->cc);
            }

            if ($this->bcc !== []) {
                $message->bcc($this->bcc);
            }

            if ($this->fromEmail) {
                $message->from($this->fromEmail);
            }

            if ($this->htmlBody !== null) {
                $message->html($this->htmlBody);
            }

            if ($this->body !== '') {
                $message->text($this->body);
            }

            foreach ($this->attachments as $attachment) {
                $this->applyAttachment($message, $attachment);
            }

            $this->applyHeaders($message);
        });
    }

    /**
     * Always attached, never embedded — `Illuminate\Mail\Message::embed()`
     * mints its own CID and has no way to pin it to a specific value, so it
     * cannot reproduce the `cid:` reference an inbound HTML body already
     * points at. Real inline embedding for OUTBOUND mail needs the compose
     * editor (plan Task 6) to generate a body whose `cid:` matches what gets
     * embedded — attaching regularly is the safe fallback until then; the
     * file still reaches the recipient, just as a listed attachment instead
     * of inline.
     *
     * @param  array{disk: string, path: string, filename: string, mimeType: ?string, isInline: bool, contentId: ?string}  $attachment
     */
    private function applyAttachment(mixed $message, array $attachment): void
    {
        $disk = Storage::disk($attachment['disk'] ?? 'local');
        $absolutePath = $disk->path($attachment['path']);
        $options = array_filter(['as' => $attachment['filename'] ?? null, 'mime' => $attachment['mimeType'] ?? null]);

        $message->attach($absolutePath, $options);
    }

    /**
     * Apply the optional threading headers to the outgoing message.
     *
     * Message-ID is set explicitly rather than left to Symfony's generator so
     * the sender already knows the id at dispatch time and can store it
     * alongside the record the mail belongs to.
     */
    private function applyHeaders(mixed $message): void
    {
        if ($this->headers === []) {
            return;
        }

        if (! empty($this->headers['replyTo'])) {
            $message->replyTo($this->headers['replyTo']);
        }

        $headers = $message->getSymfonyMessage()->getHeaders();

        if (! empty($this->headers['messageId'])) {
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $this->headers['messageId']);
        }

        if (! empty($this->headers['inReplyTo'])) {
            $headers->addIdHeader('In-Reply-To', $this->headers['inReplyTo']);
        }

        if (! empty($this->headers['references'])) {
            $headers->addIdHeader('References', (array) $this->headers['references']);
        }
    }
}
