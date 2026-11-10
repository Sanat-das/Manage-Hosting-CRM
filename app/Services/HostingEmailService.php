<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\HostingAccount;
use App\Services\Concerns\BuildsEmailVariables;
use App\Support\Logging\AppLog;

/**
 * Sends the 'service_suspended' email to the customer.
 *
 * The template has been seeded and active for a long time, but nothing ever
 * sent it: a service suspended for non-payment said nothing to the customer
 * at all. The sibling of OrderEmailService and InvoiceEmailService — same
 * shape, same template rendering, same quiet skip with a log line.
 */
final class HostingEmailService
{
    use BuildsEmailVariables;

    /**
     * Tell the customer that the service was suspended for non-payment.
     *
     * @param  string  $reason  Why the service was suspended, in plain words.
     * @param  string|null  $invoiceNo  The unpaid invoice, when there is one.
     * @return bool true when the email was queued, false when skipped.
     */
    public function sendSuspended(HostingAccount $account, string $reason, ?string $invoiceNo = null): bool
    {
        $email = $account->customer?->user?->email;

        if (! $email) {
            AppLog::billing()->info('Service suspension email skipped: customer has no linked user email.', [
                'account_id' => $account->id,
            ]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', 'service_suspended')
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            AppLog::billing()->info('Service suspension email skipped: template not found.', [
                'account_id' => $account->id,
            ]);

            return false;
        }

        $vars = array_merge($this->brandingVariables(), [
            'customer_name' => (string) ($account->customer?->full_name ?? ''),
            'service' => (string) ($account->product?->name ?? 'Service'),
            'domain' => (string) ($account->domain ?? ''),
            'reason' => $reason,
            'invoice_no' => (string) ($invoiceNo ?? ''),
        ]);

        [$subject, $body] = $this->renderTemplate($template, $vars);

        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        $htmlBody = null;
        $plainBody = $body;

        if ($this->isHtml($template->body)) {
            $htmlBody = $body;
            $plainBody = $this->toPlainText($body);
        }

        SendEmail::dispatch(
            $email,
            $subject,
            $plainBody,
            null,
            [],
            [],
            [],
            $htmlBody,
            templateName: 'service_suspended',
            customerId: $account->customer_id,
        );

        return true;
    }
}
