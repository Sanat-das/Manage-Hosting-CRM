<?php

/**
 * Soft-light redesign previews: renders every canonical template with sample
 * values to standalone HTML files under light/previews/, so the new design can
 * be eyeballed in a browser.
 *
 * Adapted from render-preview.php (same values map) — only the output
 * directory changed.
 *
 * This is a design aid, not part of the acceptance check — the senders supply
 * the real values.
 *
 * Run: php .opencode/reports/2026-09-29-email-template-redesign/light/render-preview.php
 */

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$logo = 'data:image/svg+xml;base64,'.base64_encode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="140" height="36">'
    .'<rect width="140" height="36" rx="8" fill="#2563eb"/>'
    .'<text x="70" y="24" font-family="Arial" font-size="16" font-weight="bold" fill="#ffffff" text-anchor="middle">ACME</text>'
    .'</svg>'
);

$values = [
    'app_name' => 'Acme Cloud',
    'app_url' => 'https://portal.acme.example',
    'app_logo_url' => $logo,
    'logo_url' => $logo,
    'tagline' => 'Hosting, domains & cloud',
    'primary_color' => '#2563eb',
    'footer_text' => 'Acme Cloud Pvt Ltd · GSTIN 27AAAAA0000A1Z5',
    'year' => '2026',
    'current_year' => '2026',
    'login_url' => 'https://portal.acme.example/login',
    'support_email' => 'support@acme.example',
    'support_url' => 'https://portal.acme.example/support',
    'company_name' => 'Acme Cloud Pvt Ltd',
    'company_email' => 'billing@acme.example',
    'company_phone' => '+91 98200 12345',
    'company_address' => '4th Floor, Sunrise Tower, Andheri East, Mumbai 400069',
    'company_address_html' => '4th Floor, Sunrise Tower<br>Andheri East, Mumbai 400069',
    'currency' => 'INR',
    'currency_symbol' => '₹',
    'name' => 'Priya Sharma',
    'customer_name' => 'Priya Sharma',
    'client_name' => 'Priya Sharma',
    'customer_email' => 'priya@sharmadesign.example',
    'customer_company' => 'Sharma Design Studio',
    'customer_id' => 'CUS-00042',
    'order_no' => 'ORD-2026-00142',
    'order_number' => 'ORD-2026-00142',
    'order_id' => '142',
    'order_date' => 'Sep 28, 2026',
    'status' => 'active',
    'status_label' => 'Pending',
    'billing_cycle' => 'Monthly',
    'product_name' => 'cPanel Business Hosting',
    'domain' => 'sharmadesign.example',
    'domain_name' => 'sharmadesign.example',
    'total' => '1,499.00',
    'amount' => '1,499.00',
    'total_formatted' => '₹1,499.00',
    'amount_due' => '1,499.00',
    'balance' => '1,499.00',
    'due_amount' => '1,499.00',
    'balance_formatted' => '₹1,499.00',
    'subtotal' => '1,270.00',
    'amount_subtotal' => '1,270.00',
    'tax' => '229.00',
    'tax_amount' => '229.00',
    'discount' => '0.00',
    'paid_amount' => '0.00',
    'amount_paid' => '1,499.00',
    'subtotal_formatted' => '₹1,270.00',
    'currency_total' => '₹1,499.00',
    'invoice_no' => 'INV-2026-00871',
    'invoice_number' => 'INV-2026-00871',
    'invoice_id' => '871',
    'invoice_date' => 'Sep 28, 2026',
    'due_date' => 'Oct 5, 2026',
    'paid_at' => 'Sep 29, 2026',
    'payment_date' => 'Sep 29, 2026',
    'notes' => '',
    'order_no_raw' => 'ORD-2026-00142',
    'invoice_url' => 'https://portal.acme.example/client/invoices/871',
    'view_invoice_url' => 'https://portal.acme.example/client/invoices/871',
    'pay_url' => 'https://portal.acme.example/client/invoices/871/pay',
    'payment_url' => 'https://portal.acme.example/client/invoices/871/pay',
    'pdf_url' => 'https://portal.acme.example/client/invoices/871/pdf',
    'invoices_url' => 'https://portal.acme.example/client/invoices',
    'order_url' => 'https://portal.acme.example/client/orders/142',
    'view_order_url' => 'https://portal.acme.example/client/orders/142',
    'orders_url' => 'https://portal.acme.example/client/orders',
    'activation_date' => 'Sep 29, 2026',
    'service_username' => 'sharmadesign',
    'service_password' => 'S3cure-Passw0rd!',
    'service_ip' => '203.0.113.24',
    'service_nameservers' => 'ns1.acme.example, ns2.acme.example',
    'control_panel_url' => 'https://sharmadesign.example/cpanel',
    'service_credentials' => '<table role="presentation" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;margin:18px 0;">'
        .'<tr><td style="padding:8px 14px;font-size:13px;color:#64748b;">Control panel</td><td style="padding:8px 14px;font-size:14px;color:#0f172a;font-weight:600;">https://sharmadesign.example/cpanel</td></tr>'
        .'<tr><td style="padding:8px 14px;font-size:13px;color:#64748b;">Username</td><td style="padding:8px 14px;font-size:14px;color:#0f172a;font-weight:600;">sharmadesign</td></tr>'
        .'<tr><td style="padding:8px 14px;font-size:13px;color:#64748b;">Password</td><td style="padding:8px 14px;font-size:14px;color:#0f172a;font-weight:600;">S3cure-Passw0rd!</td></tr>'
        .'</table>'
        .'<p style="font-size:13px;color:#64748b;">Please change this password after your first login.</p>',
    'service_username_note' => '',
    'reason' => 'Outstanding invoice not paid',
    'cancellation_date' => 'Sep 29, 2026',
    'data_retention_days' => '30',
    'reset_link' => 'https://portal.acme.example/password/reset/abc123',
    'chat_id' => '318',
    'chat_department' => 'Support',
    'chat_date' => 'Sep 28, 2026',
    'chat_started_at' => 'Sep 28, 2026 14:02',
    'chat_closed_at' => 'Sep 28, 2026 14:21',
    'chat_message_count' => '6',
    'transcript_html' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #f1f5f9;"><div style="font-size:12px;color:#64748b;margin-bottom:2px;">Priya Sharma &middot; 14:02</div><div style="font-size:14px;color:#0f172a;line-height:1.5;white-space:pre-wrap;">Hi, my email forms are bouncing since this morning.</div></td></tr>'
        .'<tr><td style="padding:10px 0;border-bottom:1px solid #f1f5f9;"><div style="font-size:12px;color:#64748b;margin-bottom:2px;">Support &middot; 14:05</div><div style="font-size:14px;color:#0f172a;line-height:1.5;white-space:pre-wrap;">Thanks for reaching out — I am checking your DNS records now.</div></td></tr>'
        .'</table>',
    'transcript_text' => "Priya Sharma: ...\nSupport: ...",
    'ticket_no' => 'TKT-2026-00042',
    'subject' => 'Cannot send mail from my domain',
    'department' => 'Technical Support',
    'priority' => 'high',
    'message' => 'We have fixed it.',
];

$ref = new ReflectionClass(Database\Seeders\EmailTemplateSeeder::class);
$templates = $ref->getReflectionConstant('TEMPLATES')->getValue();

$outDir = __DIR__.'/previews';

if (! is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

foreach ($templates as $template) {
    $render = static function (string $text) use ($values): string {
        return preg_replace_callback(
            '/\{\{([a-z_]+)\}\}/',
            static fn (array $m): string => $values[$m[1]] ?? '?'.$m[1].'?',
            $text,
        ) ?? $text;
    };

    $body = $render($template['body']);
    $subject = $render($template['subject']);
    $isHtml = str_contains($body, '<table') || str_contains($body, '<!doctype');
    $file = $outDir.'/'.$template['name'].($isHtml ? '.html' : '.txt');

    file_put_contents($file, $body);

    echo sprintf("%-28s -> %s  (%s)\n", $template['name'], basename($file), $subject);
}

echo "\nPreviews written to {$outDir}\n";