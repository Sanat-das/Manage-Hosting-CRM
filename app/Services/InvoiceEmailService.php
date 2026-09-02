<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * Sends invoice emails to the customer from the admin-managed
 * 'invoice_created' template.
 *
 * Shared by the order-creation flow ("Send Email" checkbox on the order
 * form, via OrderController) and the invoice page "Send Invoice" action so
 * both render identically. send() returns whether the email was dispatched;
 * it is skipped (quietly, with a log line) when the customer has no linked
 * user email or the template is missing/inactive.
 */
final class InvoiceEmailService
{
    /**
     * Dispatch the invoice email for the given invoice.
     *
     * @return bool true when the email was queued, false when skipped
     *              (no customer email or no active template)
     */
    public function send(Invoice $invoice): bool
    {
        $email = $invoice->customer?->user?->email;

        if (! $email) {
            Log::info('Invoice email skipped: customer has no linked user email.', ['invoice_id' => $invoice->id]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', 'invoice_created')
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Invoice email skipped: template "invoice_created" not found.', ['invoice_id' => $invoice->id]);

            return false;
        }

        [$subject, $body] = $this->render($template, [
            'name' => $invoice->customer?->full_name ?? 'there',
            'invoice_no' => $invoice->invoice_no,
            'due_date' => $invoice->due_date?->format('M j, Y') ?? '—',
            'total' => number_format((float) $invoice->total, 2),
            'order_no' => $invoice->order?->order_number ?? '',
        ]);

        SendEmail::dispatch($email, $subject, $body);

        return true;
    }

    /**
     * Render a template's {{placeholder}} variables (the same convention the
     * seeded demo templates use).
     *
     * @param  array<string, string>  $vars
     * @return array{0: string, 1: string} [subject, body]
     */
    private function render(EmailTemplate $template, array $vars): array
    {
        $placeholders = array_map(fn (string $key) => '{{'.$key.'}}', array_keys($vars));
        $values = array_values($vars);

        return [
            str_replace($placeholders, $values, (string) $template->subject),
            str_replace($placeholders, $values, (string) $template->body),
        ];
    }
}
