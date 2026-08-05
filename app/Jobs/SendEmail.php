<?php

namespace App\Jobs;

use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Send an email asynchronously and log the result.
 */
class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $toEmail,
        public string $subject,
        public string $body,
        public ?string $fromEmail = null,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $log = EmailLog::create([
            'to_email' => $this->toEmail,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => 'queued',
        ]);

        try {
            // Use raw mail (no Mailable class needed for now)
            \Illuminate\Support\Facades\Mail::raw($this->body, function ($message) {
                $message->to($this->toEmail)
                    ->subject($this->subject);

                if ($this->fromEmail) {
                    $message->from($this->fromEmail);
                }
            });

            $log->update(['status' => 'sent']);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
