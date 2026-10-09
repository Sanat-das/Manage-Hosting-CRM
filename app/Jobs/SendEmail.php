<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Support\SecretRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     *                                                                                                                                         file paths on disk, not raw bytes — keeps queued job payloads small.
     *                                                                                                                                         `isInline`+`contentId` embed the file for a `cid:` reference in `$htmlBody`
     *                                                                                                                                         instead of listing it as a downloadable attachment.
     * @param  ?string  $logBody  what to store on the EmailLog row INSTEAD of `$body`. The sent
     *                            message is unaffected. Used by mail that legitimately carries a
     *                            secret the recipient needs but the `emails` table must not keep
     *                            forever (provisioning credentials — see WelcomeMailer). Null
     *                            keeps the previous behaviour of logging the body verbatim.
     */
    /**
     * Stable idempotency key for this dispatch, minted once when the job is
     * constructed. It travels in the serialized payload, so every queue retry
     * of the SAME dispatch resolves to the one EmailLog row it created instead
     * of logging a fresh duplicate.
     */
    public string $logKey;

    /**
     * @param  string|list<string>  $toEmail
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
        public ?string $logBody = null,
        public ?string $templateName = null,
        public ?int $customerId = null,
    ) {
        $this->logKey = (string) Str::uuid();
        $this->onQueue('emails');
    }

    /**
     * What gets written to the EmailLog row. Always used for both the insert
     * and the retry lookup, so a redacted body still de-duplicates correctly.
     */
    private function loggedBody(): string
    {
        // A caller-supplied logBody still wins the body choice, but either way
        // the stored value is scrubbed by the shared secret filter. WelcomeMailer
        // relies on this pass leaving its literal `[redacted]` marker untouched.
        return SecretRedactor::redact($this->logBody ?? $this->body);
    }

    public function handle(): void
    {
        $log = $this->resolveLog();

        try {
            if ($this->htmlBody !== null || $this->attachments !== []) {
                $this->sendRich();
            } else {
                // Use raw mail (no Mailable class needed for now)
                Mail::raw($this->body, function ($message) {
                    $message->to($this->toEmail)
                        ->subject($this->subject);

                    // Cc/Bcc belong on BOTH paths. They used to be applied only
                    // in sendRich(), so a plain-text ticket reply with a Cc
                    // reached the To alone and the sender was told it had gone
                    // out — a silent delivery failure, not a visible one.
                    if ($this->cc !== []) {
                        $message->cc($this->cc);
                    }

                    if ($this->bcc !== []) {
                        $message->bcc($this->bcc);
                    }

                    if ($this->fromEmail) {
                        $message->from($this->fromEmail);
                    }

                    $this->applyHeaders($message);
                });
            }

            $log->update(['status' => 'sent']);
        } catch (\Throwable $e) {
            $attempts = method_exists($this, 'attempts') ? $this->attempts() : 1;
            // Append rather than overwrite: a retried job that failed before
            // keeps the earlier reason, with this attempt's message below it.
            $log->update([
                'status' => 'failed',
                'error' => trim(($log->error ? $log->error."\n" : '').($attempts > 1 ? "[attempt {$attempts}] " : '').$e->getMessage()),
            ]);
            throw $e;
        }
    }

    /**
     * Resolve the EmailLog row this dispatch belongs to.
     *
     * The primary lookup is by `log_key`: a queue retry re-runs handle() with
     * the SAME serialized payload, so the key it carries points straight at the
     * row the first attempt created — no guessing by content, and no duplicate
     * rows for one logical send. A retry bumps `attempts` and puts the row back
     * to queued, keeping any error the previous attempt recorded.
     *
     * Only when no key matches AND we are past the first attempt do we fall
     * back to the old content lookup (same recipient + subject + body in a
     * non-sent state) — that path exists for jobs queued before this key
     * existed. A match there is stamped with the key so subsequent retries use
     * the fast path. With no match, a brand new row is created.
     */
    private function resolveLog(): EmailLog
    {
        $toEmail = is_array($this->toEmail) ? implode(', ', $this->toEmail) : $this->toEmail;
        $ccEmails = $this->cc !== [] ? implode(', ', $this->cc) : null;
        $bccEmails = $this->bcc !== [] ? implode(', ', $this->bcc) : null;

        // Retry of this exact dispatch: found by its own key.
        $retry = EmailLog::query()->where('log_key', $this->logKey)->first();
        if ($retry !== null) {
            $retry->forceFill([
                'attempts' => ((int) $retry->attempts) + 1,
                'status' => 'queued',
            ])->save();

            return $retry;
        }

        try {
            $attempts = method_exists($this, 'attempts') ? $this->attempts() : 1;
            if ($attempts > 1) {
                // Legacy row from a job queued before log_key existed — no key
                // to match on, so fall back to content and stamp the key on.
                // `where('cc_emails', null)` compiles to `= NULL`, which matches
                // nothing — the null case has to go through whereNull or every
                // retry of a plain message would create a fresh row.
                $existing = EmailLog::query()
                    ->where('to_email', $toEmail)
                    ->when($ccEmails === null, fn ($q) => $q->whereNull('cc_emails'), fn ($q) => $q->where('cc_emails', $ccEmails))
                    ->when($bccEmails === null, fn ($q) => $q->whereNull('bcc_emails'), fn ($q) => $q->where('bcc_emails', $bccEmails))
                    ->where('subject', $this->subject)
                    ->where('body', $this->loggedBody())
                    ->whereIn('status', ['queued', 'failed'])
                    ->latest('id')
                    ->first();
                if ($existing !== null) {
                    $existing->forceFill([
                        'status' => 'queued',
                        'error' => null,
                        'log_key' => $this->logKey,
                    ])->save();

                    return $existing;
                }
            }
        } catch (\Throwable) {
            // Fall through to create.
        }

        // The payload records what was asked for so a failed row can be resent.
        // htmlBody is deliberately NOT captured — only the redacted/logBody text
        // is kept, matching what is stored in `body`.
        $payload = [
            'to' => $this->toEmail,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'body' => $this->loggedBody(),
            'from_email' => $this->fromEmail,
            'attachments' => $this->attachments,
            'headers' => $this->headers,
            'template_name' => $this->templateName,
            'customer_id' => $this->customerId,
        ];

        // forceFill so the always-written audit columns cannot be silently
        // dropped if the model's fillable list changes; `payload` is passed
        // as an array and cast to JSON by the model.
        $log = new EmailLog;
        $log->forceFill([
            'to_email' => $toEmail,
            'cc_emails' => $ccEmails,
            'bcc_emails' => $bccEmails,
            'subject' => $this->subject,
            'body' => $this->loggedBody(),
            'status' => 'queued',
            'log_key' => $this->logKey,
            'from_email' => $this->fromEmail,
            'template_name' => $this->templateName,
            'customer_id' => $this->customerId,
            'attempts' => 1,
            'payload' => $payload,
        ]);
        $log->save();

        return $log;
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
        // Ticket mail is transactional and must not be re-imported if it loops
        // back into the polled INBOX (BCC to self, inbox copy). Setting
        // Auto-Submitted lets TicketMailParser's AUTOMATED_HEADERS drop it via
        // the auto-submitted guard, and the self-loop username guard is the
        // second line of defence. For ticket messages (those with a custom
        // Message-ID) we set auto-submitted:no explicitly; for all other mail
        // we do not override a value the caller may have set.
        $isTicketMail = ! empty($this->headers['messageId']);

        if (! empty($this->headers['replyTo'])) {
            $message->replyTo($this->headers['replyTo']);
        }

        // If caller did not provide headers at all but this is ticket mail
        // (detected via messageId set separately), we still need the
        // Auto-Submitted header. So we handle headers even when the array is
        // otherwise empty for ticket mail.
        if ($this->headers === [] && ! $isTicketMail) {
            return;
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

        // Explicit autoSubmitted from caller (TicketMailService sends 'no').
        if (! empty($this->headers['autoSubmitted'])) {
            if (! $headers->has('Auto-Submitted')) {
                $headers->addTextHeader('Auto-Submitted', (string) $this->headers['autoSubmitted']);
            }
        } elseif ($isTicketMail) {
            // Ensure ticket outbound is not treated as auto-generated on re-import.
            // Value "no" is the RFC 3834 signal that this is NOT auto-submitted;
            // TicketMailParser's regex /^(?!no\b).+/i treats "no" as human mail
            // so the message can still be imported when legitimately replied to,
            // while any actual auto-reply (auto-replied, auto-generated) is
            // dropped. We explicitly set it so downstream filters know this was
            // a user-initiated ticket reply, not a bounce.
            if (! $headers->has('Auto-Submitted')) {
                $headers->addTextHeader('Auto-Submitted', 'no');
            }
        }

        if ($isTicketMail && ! $headers->has('X-Auto-Response-Suppress')) {
            $headers->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
        }
    }
}
