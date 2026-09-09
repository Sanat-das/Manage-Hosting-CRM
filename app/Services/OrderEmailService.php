<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Services\Concerns\BuildsEmailVariables;
use Illuminate\Support\Facades\Log;

/**
 * Sends the order confirmation email from the admin-managed
 * 'order_confirmation' template.
 *
 * The sibling of InvoiceEmailService, and shared by every entry point that
 * creates an order — the admin order form, the admin cart and the storefront —
 * so all three render the same template with the same variables. The storefront
 * sent no confirmation at all before this existed, and the admin form built a
 * three-variable map ({{name}}, {{order_no}}, {{total}}) which left the seeded
 * HTML template's branding placeholders (`{{app_logo_url}}`, `{{company_name}}`,
 * `{{currency_symbol}}` …) rendering as literal text.
 *
 * send() returns whether the email was queued; it is skipped quietly (with a
 * log line) when the customer has no linked user email or the template is
 * missing/inactive.
 */
final class OrderEmailService
{
    use BuildsEmailVariables;

    public function send(Order $order, string $templateName = 'order_confirmation'): bool
    {
        $email = $order->customer?->user?->email;

        if (! $email) {
            Log::info('Order confirmation email skipped: customer has no linked user email.', ['order_id' => $order->id]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', $templateName)
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Order confirmation email skipped: template not found.', [
                'order_id' => $order->id,
                'template' => $templateName,
            ]);

            return false;
        }

        [$subject, $body] = $this->renderTemplate($template, $this->buildVariables($order));

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
     * The canonical variable map for order emails: shared branding plus the
     * order's own identity, totals and portal URLs. Aliases are deliberate —
     * the seeded templates and the older demo templates disagree about whether
     * the order number is {{order_no}} or {{order_number}}, and both must
     * resolve rather than leaking a raw placeholder.
     *
     * @return array<string, string>
     */
    public function buildVariables(Order $order): array
    {
        $branding = $this->brandingVariables();
        $appUrl = $branding['app_url'];
        $symbol = $branding['currency_symbol'];

        $customer = $order->customer;
        $customerName = $customer?->full_name ?? 'there';

        $orderNo = (string) $order->order_number;
        $total = number_format((float) $order->total, 2);

        $invoice = $order->invoices()->latest('id')->first();
        $invoiceNo = (string) ($invoice?->invoice_no ?? '');

        $invoiceUrl = $invoice !== null
            ? $this->safeRoute('client.invoices.show', $invoice->id, $appUrl.'/client/invoices/'.$invoice->id)
            : $this->safeRoute('client.invoices.index', null, $appUrl.'/client/invoices');

        $payUrl = $invoice !== null
            ? $this->safeRoute('client.invoices.pay', $invoice->id, $invoiceUrl.'/pay')
            : $invoiceUrl;

        // The order's own page in the client portal. The seeded template's
        // "View Order" button used to be a hardcoded {{app_url}}/client/orders,
        // which 404'd because the portal had no order pages at all.
        $orderUrl = $this->safeRoute('client.orders.show', $order->id, $appUrl.'/client/orders/'.$order->id);

        return $branding + [
            // Customer (aliases for template flexibility)
            'name' => $customerName,
            'customer_name' => $customerName,
            'client_name' => $customerName,
            'customer_email' => $customer?->user?->email ?? '',
            'customer_company' => $customer?->company ?? '',
            'customer_id' => $customer ? $customer->display_id : '',

            // Order core
            'order_no' => $orderNo,
            'order_number' => $orderNo,
            'order_id' => (string) $order->id,
            'order_date' => $order->created_at?->format('M j, Y') ?? now()->format('M j, Y'),
            'status' => (string) $order->status,
            'status_label' => ucfirst(str_replace('_', ' ', (string) $order->status)),
            'billing_cycle' => ucfirst(str_replace('_', ' ', (string) $order->billing_cycle)),
            'product_name' => (string) ($order->product?->name ?? ''),
            'domain' => (string) ($order->domain_name ?? ''),
            'domain_name' => (string) ($order->domain_name ?? ''),

            // Amounts
            'total' => $total,
            'amount' => $total,
            'total_formatted' => $symbol.$total,

            // Related invoice (blank when the order was created without one)
            'invoice_no' => $invoiceNo,
            'invoice_number' => $invoiceNo,

            // URLs
            'invoice_url' => $invoiceUrl,
            'view_invoice_url' => $invoiceUrl,
            'pay_url' => $payUrl,
            'payment_url' => $payUrl,
            'order_url' => $orderUrl,
            'view_order_url' => $orderUrl,
            'orders_url' => $this->safeRoute('client.orders.index', null, $appUrl.'/client/orders'),
        ];
    }
}
