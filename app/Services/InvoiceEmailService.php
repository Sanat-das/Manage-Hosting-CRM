<?php

namespace App\Services;

use App\Jobs\SendEmail;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Support\AppSettings;
use App\Support\Branding;
use Illuminate\Support\Facades\Log;

/**
 * Sends invoice emails to the customer from the admin-managed
 * 'invoice_created' template (and siblings).
 *
 * Centralizes ALL invoice email variables so templates never render
 * raw {{placeholders}}. Company / branding / URL variables are
 * resolved here from settings with safe fallbacks — templates only
 * reference them.
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
     * @param string $templateName allow reuse for invoice_created / invoice_overdue_reminder / payment_received
     * @return bool true when the email was queued, false when skipped
     */
    public function send(Invoice $invoice, string $templateName = 'invoice_created'): bool
    {
        $email = $invoice->customer?->user?->email;

        if (! $email) {
            Log::info('Invoice email skipped: customer has no linked user email.', ['invoice_id' => $invoice->id]);

            return false;
        }

        $template = EmailTemplate::query()
            ->where('name', $templateName)
            ->where('status', 'active')
            ->first();

        if ($template === null) {
            Log::info('Invoice email skipped: template not found.', ['invoice_id' => $invoice->id, 'template' => $templateName]);

            return false;
        }

        $vars = $this->buildVariables($invoice);

        [$subject, $body] = $this->render($template, $vars);

        // Strip admin-only "Available variables:" footer if present in DB template
        // (older/custom templates may still contain it; customers must never see it)
        $body = $this->stripAvailableVariablesFooter($body);
        $subject = $this->stripAvailableVariablesFooter($subject);

        // Detect HTML template (research: table-based 600px, bulletproof CTA)
        // — send as htmlBody with plain fallback, otherwise plain only.
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
     * Build the canonical variable map for invoice emails.
     *
     * Covers: branding, company, currency, invoice, order, customer,
     * URLs and computed balances. Null-safe with fallbacks so a missing
     * settings row never leaves a raw {{placeholder}} in the email.
     *
     * @return array<string, string>
     */
    public function buildVariables(Invoice $invoice): array
    {
        // --- Branding / app ---
        $appName = Branding::appName();
        $appUrl = rtrim((string) config('app.url', url('/')), '/');
        $logoUrl = Branding::logoUrl();
        $tagline = Branding::tagline();
        $primaryColor = Branding::primaryColor();
        $footerText = Branding::footerText();

        // --- Company (GeneralSettings via AppSettings with fallback) ---
        $companyName = $this->setting('company_name', $appName);
        $companyEmail = $this->setting('company_email', (string) config('mail.from.address', ''));
        $companyPhone = $this->setting('company_phone', '');
        $companyAddress = $this->setting('company_address', '');
        // Multi-line address -> single line + html <br> variant
        $companyAddressLine = $companyAddress !== '' ? preg_replace('/\s*\n\s*/', ', ', $companyAddress) : '';
        $companyAddressHtml = $companyAddress !== '' ? nl2br(htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8')) : '';

        // --- Currency ---
        $currency = $this->setting('currency', 'INR');
        $currencySymbol = $this->setting('catalog_currency_symbol', '₹');
        // Fallback symbol map if setting blank
        if ($currencySymbol === '') {
            $currencySymbol = match (strtoupper($currency)) {
                'USD' => '$',
                'EUR' => '€',
                'GBP' => '£',
                'INR' => '₹',
                default => $currency . ' ',
            };
        }

        // --- Customer ---
        $customer = $invoice->customer;
        $customerName = $customer?->full_name ?? 'there';
        // Legacy alias {{name}} keep in sync
        $customerEmail = $customer?->user?->email ?? '';
        $customerCompany = $customer?->company ?? '';
        $customerId = $customer ? $customer->display_id : '';

        // --- Invoice / Order ---
        $invoiceNo = (string) $invoice->invoice_no;
        $orderNo = $invoice->order?->order_number ?? '';
        $orderNoHashed = $orderNo !== '' ? '#' . ltrim($orderNo, '#') : '';
        $status = (string) $invoice->status;
        $statusLabel = $invoice->status_label ?? ucfirst($status);

        $invoiceDate = $invoice->created_at?->format('M j, Y') ?? now()->format('M j, Y');
        $dueDate = $invoice->due_date?->format('M j, Y') ?? '—';
        $paidAt = $invoice->paid_at?->format('M j, Y') ?? '';

        // Amounts
        $total = number_format((float) $invoice->total, 2);
        $subtotal = number_format((float) ($invoice->amount ?? $invoice->total), 2);
        $tax = number_format((float) ($invoice->tax ?? 0), 2);
        $discount = number_format((float) ($invoice->discount ?? 0), 2);
        $paidAmount = number_format((float) ($invoice->paid_amount ?? 0), 2);
        $balance = number_format($invoice->dueAmount(), 2);
        $amountDue = $balance;

        // Currency-formatted variants
        $totalFormatted = $currencySymbol . $total;
        $balanceFormatted = $currencySymbol . $balance;
        $subtotalFormatted = $currencySymbol . $subtotal;

        // --- URLs (client portal) ---
        $invoiceUrl = $this->safeRoute('client.invoices.show', $invoice->id, $appUrl . '/client/invoices/' . $invoice->id);
        $payUrl = $this->safeRoute('client.invoices.pay', $invoice->id, $invoiceUrl . '/pay');
        $pdfUrl = $this->safeRoute('client.invoices.pdf', $invoice->id, $appUrl . '/client/invoices/' . $invoice->id . '/pdf');
        $invoicesUrl = $this->safeRoute('client.invoices.index', null, $appUrl . '/client/invoices');
        $loginUrl = $appUrl . '/login';
        $currentYear = date('Y');

        return [
            // Brand / company (new)
            'app_name' => $appName,
            'app_url' => $appUrl,
            'app_logo_url' => $logoUrl,
            'logo_url' => $logoUrl,
            'tagline' => $tagline,
            'primary_color' => $primaryColor,
            'footer_text' => $footerText,

            'company_name' => $companyName,
            'company_email' => $companyEmail,
            'company_phone' => $companyPhone,
            'company_address' => $companyAddressLine,
            'company_address_raw' => $companyAddress,
            'company_address_html' => $companyAddressHtml,

            // Currency (fix: these were missing before)
            'currency' => $currency,
            'currency_code' => $currency,
            'currency_symbol' => $currencySymbol,

            // Customer (aliases for template flexibility)
            'name' => $customerName,
            'customer_name' => $customerName,
            'client_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_company' => $customerCompany,
            'customer_id' => $customerId,

            // Invoice core
            'invoice_no' => $invoiceNo,
            'invoice_number' => $invoiceNo,
            'invoice_id' => (string) $invoice->id,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'paid_at' => $paidAt,
            'payment_date' => $paidAt,
            'status' => $status,
            'status_label' => $statusLabel,
            'notes' => (string) ($invoice->notes ?? ''),

            // Amounts — raw and formatted
            'total' => $total,
            'amount' => $total,
            'amount_due' => $amountDue,
            'balance' => $balance,
            'due_amount' => $balance,
            'subtotal' => $subtotal,
            'amount_subtotal' => $subtotal,
            'tax' => $tax,
            'tax_amount' => $tax,
            'discount' => $discount,
            'paid_amount' => $paidAmount,
            'amount_paid' => $paidAmount,

            'total_formatted' => $totalFormatted,
            'balance_formatted' => $balanceFormatted,
            'subtotal_formatted' => $subtotalFormatted,
            'currency_total' => $totalFormatted,

            // Order
            'order_no' => $orderNo,
            'order_number' => $orderNoHashed !== '' ? $orderNoHashed : $orderNo,
            'order_no_raw' => $orderNo,

            // URLs
            'invoice_url' => $invoiceUrl,
            'view_invoice_url' => $invoiceUrl,
            'pay_url' => $payUrl,
            'payment_url' => $payUrl,
            'pdf_url' => $pdfUrl,
            'invoices_url' => $invoicesUrl,
            'login_url' => $loginUrl,

            // Misc
            'year' => $currentYear,
            'current_year' => $currentYear,
            'support_email' => $companyEmail,
            'support_url' => $appUrl . '/support',
        ];
    }

    /**
     * Render a template's {{placeholder}} variables.
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

    private function isHtml(?string $body): bool
    {
        if ($body === null || $body === '') {
            return false;
        }

        return str_contains($body, '<table') || str_contains($body, '<html') || str_contains($body, '<div') || str_contains($body, '<!doctype');
    }

    private function toPlainText(string $html): string
    {
        // Keep links readable: <a href="...">text</a> -> text (url)
        $text = preg_replace('/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function stripAvailableVariablesFooter(string $content): string
    {
        // Matches "--- Available variables:" or "-- Available variables:" plus everything after
        // Handles both plain-text and HTML bodies. Keep it non-greedy and case-insensitive.
        // We do two passes: HTML comment variant and visible text variant.
        $content = preg_replace('/\n---\s*Available variables:.*$/is', '', $content) ?? $content;
        $content = preg_replace('/\n--\s*Available variables:.*$/is', '', $content) ?? $content;
        $content = preg_replace('/<!--\s*Available variables:.*?-->/is', '', $content) ?? $content;

        return rtrim($content);
    }

    private function setting(string $key, string $fallback = ''): string
    {
        try {
            $v = AppSettings::get($key);
            if ($v !== null && trim($v) !== '') {
                return trim($v);
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }

    private function safeRoute(string $name, mixed $param, string $fallback): string
    {
        try {
            if ($param === null) {
                return (string) route($name);
            }

            return (string) route($name, $param);
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
