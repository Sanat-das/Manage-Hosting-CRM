<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\UpgradeRequest;
use App\Services\Concerns\BuildsEmailVariables;
use Illuminate\Support\Facades\Log;

/**
 * Sends the upgrade lifecycle emails — 'upgrade_requested' on place,
 * 'upgrade_applied' on materialization, 'upgrade_cancelled' on cancel —
 * from the admin-managed templates of the same names.
 *
 * The structural sibling of OrderEmailService: renderTemplate +
 * buildVariables produce the subject/body, SendEmail is dispatched with the
 * same HTML/plain split, and the same quiet skips apply when the customer has
 * no linked user email or the template is missing/inactive.
 */
final class UpgradeEmailService
{
    use BuildsEmailVariables;

    public function send(UpgradeRequest $request, string $event): bool
    {
        $email = $request->customer?->user?->email;

        if (! $email) {
            Log::info('Upgrade email skipped: customer has no linked user email.', [
                'upgrade_request_id' => $request->id,
                'event' => $event,
            ]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', $event)
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Upgrade email skipped: template not found.', [
                'upgrade_request_id' => $request->id,
                'template' => $event,
            ]);

            return false;
        }

        [$subject, $body] = $this->renderTemplate($template, $this->buildVariables($request));

        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        $htmlBody = null;
        $plainBody = $body;

        if ($this->isHtml($template->body)) {
            $htmlBody = $body;
            $plainBody = $this->toPlainText($body);
        }

        SendEmail::dispatch($email, $subject, $plainBody, null, [], [], [], $htmlBody);

        return true;
    }

    /**
     * The variable map for upgrade emails: shared branding plus the upgrade's
     * identity, products and money — {{upgrade_no}}, {{order_number}},
     * {{from_product_name}}, {{to_product_name}}, {{amount}}, {{credit_amount}},
     * {{next_due_date}}.
     *
     * @return array<string, string>
     */
    public function buildVariables(UpgradeRequest $request): array
    {
        return $this->brandingVariables() + [
            'upgrade_no' => (string) $request->upgrade_no,
            'order_number' => (string) $request->order->order_no,
            'from_product_name' => (string) ($request->fromProduct?->name ?? ''),
            'to_product_name' => (string) ($request->toProduct?->name ?? ''),
            'amount' => number_format((float) $request->payable, 2),
            'credit_amount' => number_format((float) $request->credit_amount, 2),
            'next_due_date' => $request->order->next_billing_date?->format('Y-m-d') ?? '—',
        ];
    }
}
