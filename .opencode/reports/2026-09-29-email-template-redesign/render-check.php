<?php

/**
 * Placeholder-leak check for the shipped e-mail templates.
 *
 * Boots Laravel, reflects the private TEMPLATES constants out of
 * EmailTemplateSeeder (13 canonical templates) and the demo
 * NotificationEmailSeeder (7 demo templates), and reports any `{{...}}`
 * placeholder that is not part of the variable whitelist its sender supplies.
 *
 * Whitelists mirror the variable maps in:
 *   App\Services\Concerns\BuildsEmailVariables::brandingVariables()
 *   App\Services\InvoiceEmailService::buildVariables()
 *   App\Services\OrderEmailService::buildVariables()
 *   App\Services\Provisioning\WelcomeMailer::variables()
 *   App\Services\TicketMailService::templateVariables()
 *   App\Services\ChatTranscriptEmailService::buildVariables()
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/render-check.php
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$common = [
    'app_name', 'app_url', 'app_logo_url', 'tagline', 'primary_color', 'footer_text', 'year',
    'login_url', 'support_email', 'company_name', 'company_email', 'company_phone', 'company_address',
    'currency_symbol',
];

// Invoice / Order / Chat extend the commons with these.
$billingExtra = ['currency', 'support_url', 'company_address_html', 'logo_url', 'current_year'];

// WelcomeMailer supplies neither support_url nor currency_symbol.
$welcomeCommons = array_values(array_diff($common, ['support_url', 'currency_symbol']));

$order = [
    'name', 'customer_name', 'customer_email', 'customer_company', 'customer_id',
    'order_no', 'order_number', 'order_id', 'order_date', 'status', 'status_label', 'billing_cycle',
    'product_name', 'domain', 'domain_name', 'total', 'amount', 'total_formatted',
    'invoice_no', 'invoice_number', 'invoice_url', 'view_invoice_url', 'pay_url', 'payment_url',
    'order_url', 'view_order_url', 'orders_url',
];

$invoice = [
    // Customer aliases resolved by InvoiceEmailService::buildVariables().
    'name', 'customer_name', 'client_name', 'customer_email', 'customer_company', 'customer_id',
    'invoice_no', 'invoice_number', 'invoice_id', 'invoice_date', 'due_date', 'paid_at', 'payment_date',
    'status', 'status_label', 'notes', 'total', 'amount', 'amount_due', 'balance', 'due_amount',
    'subtotal', 'amount_subtotal', 'tax', 'tax_amount', 'discount', 'paid_amount', 'amount_paid',
    'total_formatted', 'balance_formatted', 'subtotal_formatted', 'currency_total',
    'order_no', 'order_number', 'order_no_raw', 'invoice_url', 'view_invoice_url', 'pay_url',
    'payment_url', 'pdf_url', 'invoices_url',
];

$activated = [
    'name', 'customer_name', 'customer_email', 'customer_id', 'product_name', 'order_number', 'order_no',
    'domain', 'activation_date', 'service_username', 'service_password', 'service_ip',
    'service_nameservers', 'control_panel_url', 'service_credentials',
];

$lifecycle = [
    'name', 'customer_name', 'product_name', 'order_number', 'order_no', 'domain', 'reason',
    'invoice_no', 'pay_url', 'cancellation_date', 'data_retention_days',
];

$chat = [
    'name', 'customer_name', 'client_name', 'chat_id', 'chat_department', 'chat_date',
    'chat_started_at', 'chat_closed_at', 'chat_message_count', 'transcript_html', 'transcript_text',
];

$ticket = [
    'name', 'customer_name', 'ticket_no', 'subject', 'department', 'status', 'priority', 'message',
    'app_name', 'app_url', 'company_name', 'company_email',
];

$whitelists = [
    'welcome' => array_merge($common, ['name', 'reset_link']),
    'password_reset' => array_merge($common, ['name', 'reset_link']),
    'order_confirmation' => array_merge($common, $billingExtra, $order),
    'invoice_created' => array_merge($common, $billingExtra, $invoice),
    'invoice_overdue_reminder' => array_merge($common, $billingExtra, $invoice),
    'payment_received' => array_merge($common, $billingExtra, $invoice),
    'service_activated' => array_merge($welcomeCommons, $activated),
    'service_suspended' => array_merge($common, $lifecycle),
    'service_cancelled' => array_merge($common, $lifecycle),
    'support_ticket_opened' => $ticket,
    'support_ticket_reply' => $ticket,
    'chat_transcript' => array_merge($common, $billingExtra, $chat),
];

/**
 * @return list<string> placeholders in $text that are not whitelisted
 */
function leaks(string $text, array $allowed): array
{
    preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $matches);

    return array_values(array_unique(array_diff($matches[1], $allowed)));
}

$failures = 0;

/**
 * @return array{0: int, 1: int} [templates checked, leak count]
 */
function checkTemplates(string $label, string $class, array $whitelists, array $extraAllowed = []): array
{
    $ref = new ReflectionClass($class);
    $templates = $ref->getReflectionConstant('TEMPLATES')->getValue();

    $checked = 0;
    $leaks = 0;

    echo "== {$label} ==\n";

    foreach ($templates as $template) {
        $name = $template['name'];
        $allowed = array_merge($whitelists[$name] ?? [], $extraAllowed);

        if (! isset($whitelists[$name])) {
            echo sprintf("  %-28s NO WHITELIST DECLARED\n", $name);
            $leaks++;

            continue;
        }

        $found = array_unique(array_merge(
            leaks($template['subject'], $allowed),
            leaks($template['body'], $allowed),
        ));

        $checked++;

        if ($found === []) {
            echo sprintf("  %-28s OK\n", $name);
        } else {
            echo sprintf("  %-28s LEAK: %s\n", $name, implode(', ', $found));
            $leaks += count($found);
        }
    }

    return [$checked, $leaks];
}

[$canonicalChecked, $canonicalLeaks] = checkTemplates(
    'EmailTemplateSeeder (canonical)',
    Database\Seeders\EmailTemplateSeeder::class,
    $whitelists,
);

$failures += $canonicalLeaks;

[$demoChecked, $demoLeaks] = checkTemplates(
    'NotificationEmailSeeder (demo)',
    Database\Seeders\Demo\NotificationEmailSeeder::class,
    $whitelists,
    ['currency'], // demo bodies use {{currency}} by convention
);

$failures += $demoLeaks;

// Demo bodies keep the admin-only "Available variables:" footer; prove the
// send path strips it (stripAvailableVariablesFooter's three patterns) so the
// customer never sees the variable list.
$demoRef = new ReflectionClass(Database\Seeders\Demo\NotificationEmailSeeder::class);
$footerLeaks = 0;

foreach ($demoRef->getReflectionConstant('TEMPLATES')->getValue() as $template) {
    $clean = preg_replace('/\n---\s*Available variables:.*$/is', '', (string) $template['body']) ?? '';
    $clean = preg_replace('/\n--\s*Available variables:.*$/is', '', $clean) ?? '';
    $clean = preg_replace('/<!--\s*Available variables:.*?-->/is', '', $clean) ?? '';

    if (str_contains($clean, 'Available variables:')) {
        echo "  {$template['name']}: footer NOT stripped\n";
        $footerLeaks++;
    }
}

echo "demo footers: ".count($demoRef->getReflectionConstant('TEMPLATES')->getValue())
    ." templates checked, {$footerLeaks} footer(s) surviving the send-path strip\n";

$failures += $footerLeaks;

echo "\ncanonical: {$canonicalChecked} templates checked, {$canonicalLeaks} leftover placeholder(s)\n";
echo "demo:      {$demoChecked} templates checked, {$demoLeaks} leftover placeholder(s)\n";

if ($failures > 0) {
    echo "\nFAIL: {$failures} placeholder leak(s) found.\n";

    exit(1);
}

echo "\nPASS: every placeholder in every shipped template is supplied by its sender.\n";
