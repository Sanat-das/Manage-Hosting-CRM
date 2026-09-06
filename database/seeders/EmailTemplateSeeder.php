<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the canonical set of email templates that every fresh installation
 * needs. Uses updateOrInsert on `name` so re-runs are idempotent and the
 * demo seeder (NotificationEmailSeeder) can safely upsert the same keys.
 *
 * Variables use the {{placeholder}} convention that InvoiceEmailService
 * and TicketMailService support. Each template lists its available variables
 * in the footer as a reference for admins editing them.
 *
 * Invoice variables are centralized in App\Services\InvoiceEmailService::buildVariables()
 * — the seeder documents the same canonical set so admins see every placeholder
 * that will actually be replaced at send time.
 */
class EmailTemplateSeeder extends Seeder
{
    /**
     * @var list<array{name: string, subject: string, body: string, status: string}>
     */
    private const TEMPLATES = [
        // ── Account lifecycle ─────────────────────────────────────────────
        [
            'name'    => 'welcome',
            'subject' => 'Welcome to {{app_name}} — Your Account Is Ready',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

Thank you for choosing {{app_name}}. Your account has been created and is ready to use.

You can log in at any time using the link below:
{{login_url}}

If you have any questions, our support team is always happy to help — {{company_email}} / {{company_phone}}

Warm regards,
The {{app_name}} Team
{{company_name}} · {{company_address}}

BODY,
        ],

        [
            'name'    => 'password_reset',
            'subject' => 'Reset Your {{app_name}} Password',
            'status'  => 'inactive',
            'body'    => <<<'BODY'
Hi {{name}},

We received a request to reset the password for your account. Click the link below to choose a new password. This link expires in 60 minutes.

{{reset_link}}

If you did not request a password reset, you can safely ignore this email — your password will not be changed.

Regards,
The {{app_name}} Team

BODY,
        ],

        // ── Orders ────────────────────────────────────────────────────────
        [
            'name'    => 'order_confirmation',
            'subject' => 'Order {{order_number}} Confirmed — Thank You!',
            'status'  => 'active',
            'body'    => <<<'BODY'
<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:#f6f8fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8fb;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
<tr><td style="padding:28px 32px 20px;text-align:center;border-bottom:1px solid #f1f5f9;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 10px;">
<div style="font-size:13px;color:#64748b;letter-spacing:0.04em;text-transform:uppercase;">{{tagline}}</div>
</td></tr>
<tr><td style="padding:32px;">
<p style="margin:0 0 8px;font-size:16px;color:#0f172a;">Hi {{customer_name}},</p>
<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#334155;">Great news — we have received your order and it is now being processed.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:0 0 20px;">
<tr><td style="padding:16px 20px;">
<div style="font-size:13px;color:#64748b;margin-bottom:6px;">Order</div>
<div style="font-size:15px;color:#0f172a;font-weight:600;">{{order_number}}</div>
<div style="margin-top:12px;font-size:13px;color:#64748b;">Order total</div>
<div style="font-size:22px;color:#0f172a;font-weight:700;">{{currency_symbol}}{{total}}</div>
</td></tr>
</table>
<p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#475569;">You will receive a separate email once your service is activated. Quote your order number {{order_number}} when contacting support.</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:{{primary_color}};border-radius:8px;">
<a href="{{app_url}}/client/orders" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">View Order</a>
</td></tr></table>
<p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">Need help? {{company_email}} · {{company_phone}}</p>
</td></tr>
<tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
<div style="font-size:12px;color:#94a3b8;line-height:1.6;">{{company_name}} · {{company_address}}<br>{{footer_text}}</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
BODY,
        ],

        // ── Billing / Invoices ────────────────────────────────────────────
        [
            'name'    => 'invoice_created',
            'subject' => 'Invoice {{invoice_no}} from {{company_name}} — {{currency_symbol}}{{total}} due {{due_date}}',
            'status'  => 'active',
            'body'    => <<<'BODY'
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f1f5f9;padding:32px 16px;">
<tr><td align="center">
<!-- Card -->
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(15,23,42,0.10);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#0f172a;padding:32px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="52" height="52" style="display:block;margin:0 auto 14px;border-radius:12px;border:2px solid rgba(255,255,255,0.12);">
<div style="font-size:22px;font-weight:800;color:#ffffff;letter-spacing:-0.02em;line-height:1;">{{app_name}}</div>
<div style="font-size:11px;color:#64748b;margin-top:5px;letter-spacing:0.1em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero: amount due ──────────────────────────────────────── -->
<tr><td style="background:{{primary_color}};padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.65);margin-bottom:10px;">Amount Due</div>
<div style="font-size:52px;font-weight:900;color:#ffffff;letter-spacing:-0.04em;line-height:1;">{{currency_symbol}}{{total}}</div>
<div style="margin-top:14px;font-size:13px;color:rgba(255,255,255,0.75);">Invoice {{invoice_no}} &nbsp;·&nbsp; Due {{due_date}}</div>
<div style="margin-top:10px;">
<span style="display:inline-block;background:rgba(255,255,255,0.18);color:#ffffff;font-size:11px;font-weight:700;padding:4px 14px;border-radius:999px;letter-spacing:0.06em;">{{status_label}}</span>
</div>
</td></tr>

<!-- ── Greeting ──────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{customer_name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Your invoice is ready. Please review the details below and arrange payment before <strong style="color:#0f172a;">{{due_date}}</strong> to avoid any service interruption.</p>
</td></tr>

<!-- ── Invoice details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:14px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice No.</td>
<td style="padding:14px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{invoice_no}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:12px;color:#64748b;border-bottom:1px solid #f1f5f9;">Order</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{order_number}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:12px;color:#64748b;border-bottom:1px solid #e2e8f0;">Invoice Date</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{invoice_date}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:12px;color:#64748b;border-bottom:1px solid #f1f5f9;">Due Date</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#dc2626;border-bottom:1px solid #f1f5f9;">{{due_date}}</td>
</tr>
<tr style="background:#f0fdf4;">
<td style="padding:16px 18px;font-size:13px;font-weight:800;color:#166534;">Balance Due</td>
<td style="padding:16px 18px;font-size:20px;font-weight:900;color:#166534;">{{currency_symbol}}{{balance}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{pay_url}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Pay Invoice Now &rarr;</a>
</td></tr>

<!-- ── Secondary links ──────────────────────────────────────── -->
<tr><td style="padding:0 40px 28px;text-align:center;">
<a href="{{invoice_url}}" style="color:#475569;text-decoration:none;font-size:13px;margin:0 10px;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;display:inline-block;">View Invoice</a>
<a href="{{pdf_url}}" style="color:#475569;text-decoration:none;font-size:13px;margin:0 10px;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;display:inline-block;">Download PDF</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">If you have already paid, please allow 1–2 business days for it to reflect. Questions? Contact our billing team at <a href="mailto:{{company_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{company_email}}</a> or visit your <a href="{{invoices_url}}" style="color:{{primary_color}};text-decoration:none;">Invoices dashboard</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}{{company_phone}}</div>
<div style="margin-top:10px;font-size:11px;color:#94a3b8;">{{footer_text}} &nbsp;·&nbsp; <a href="{{app_url}}" style="color:#94a3b8;text-decoration:none;">{{app_url}}</a></div>
</td></tr>

</table>
<!-- Sub-footer -->
<p style="margin:16px auto 0;font-size:11px;color:#94a3b8;text-align:center;max-width:480px;line-height:1.6;">You received this because you have an account at {{app_name}}. Manage your email preferences in the <a href="{{login_url}}" style="color:#94a3b8;">client portal</a>.</p>
</td></tr>
</table>
</body>
</html>
BODY,
        ],

        [
            'name'    => 'invoice_overdue_reminder',
            'subject' => 'Action Required: Invoice {{invoice_no}} Is Overdue — {{currency_symbol}}{{balance}} due',
            'status'  => 'active',
            'body'    => <<<'BODY'
<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:#fef2f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #fecaca;">
<tr><td style="padding:20px 32px;text-align:center;background:#fef2f2;border-bottom:1px solid #fecaca;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="120" style="max-width:120px;height:auto;display:block;margin:0 auto 8px;">
<div style="font-size:11px;color:#dc2626;letter-spacing:0.08em;text-transform:uppercase;font-weight:700;">Overdue Notice</div>
</td></tr>
<tr><td style="padding:32px;">
<p style="margin:0 0 8px;font-size:16px;color:#0f172a;">Hi {{customer_name}},</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#334155;">This is a reminder that <strong>Invoice {{invoice_no}}</strong> is now overdue. To avoid suspension of your services, please arrange payment as soon as possible.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#7f1d1d;border-radius:12px;margin:0 0 20px;">
<tr><td style="padding:20px 24px;text-align:center;">
<div style="font-size:13px;color:#fecaca;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:6px;">Amount overdue</div>
<div style="font-size:28px;color:#ffffff;font-weight:800;">{{currency_symbol}}{{balance}}</div>
<div style="font-size:13px;color:#fecaca;margin-top:6px;">Invoice {{invoice_no}} · Original due {{due_date}} · {{status_label}}</div>
</td></tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fee2e2;border-radius:10px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:12px 16px;background:#fff1f2;font-size:13px;color:#9f1239;">Invoice</td><td style="padding:12px 16px;font-size:14px;color:#0f172a;font-weight:600;">{{invoice_no}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:13px;color:#9f1239;">Due date</td><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:14px;color:#0f172a;">{{due_date}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:13px;color:#9f1239;">Balance due</td><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:14px;color:#dc2626;font-weight:700;">{{currency_symbol}}{{balance}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:13px;color:#9f1239;">Order</td><td style="padding:12px 16px;border-top:1px solid #fff1f2;font-size:14px;color:#0f172a;">{{order_number}}</td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 14px;"><tr><td style="background:#dc2626;border-radius:8px;"><a href="{{pay_url}}" style="display:inline-block;padding:13px 26px;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;">Pay Now — {{currency_symbol}}{{balance}}</a></td></tr></table>
<p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">Pay online via <a href="{{invoice_url}}" style="color:#dc2626;text-decoration:none;">View Invoice</a> or log in → Invoices. If you paid in the last 1–2 business days, please disregard this notice.</p>
<p style="margin:16px 0 0;font-size:13px;color:#475569;">Need help? <a href="mailto:{{company_email}}" style="color:#dc2626;text-decoration:none;">{{company_email}}</a> · {{company_phone}} · <a href="{{support_url}}" style="color:#dc2626;text-decoration:none;">Support</a></p>
</td></tr>
<tr><td style="padding:16px 32px;background:#fff1f2;border-top:1px solid #fecaca;text-align:center;">
<div style="font-size:12px;color:#9f1239;line-height:1.6;">{{company_name}} · {{company_address}}<br>{{company_email}} · {{company_phone}}</div>
<div style="font-size:11px;color:#f87171;margin-top:6px;">{{footer_text}}</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
BODY,
        ],

        [
            'name'    => 'payment_received',
            'subject' => 'Payment Received — Thank You! Invoice {{invoice_no}} · {{currency_symbol}}{{amount_paid}}',
            'status'  => 'active',
            'body'    => <<<'BODY'
<!doctype html>
<html lang="en">
<body style="margin:0;padding:0;background:#f0fdf4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #bbf7d0;">
<tr><td style="padding:28px 32px 20px;text-align:center;border-bottom:1px solid #dcfce7;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 10px;">
<div style="display:inline-block;background:#dcfce7;color:#166534;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;padding:6px 12px;border-radius:999px;">Payment Confirmed ✓</div>
</td></tr>
<tr><td style="padding:32px;">
<p style="margin:0 0 8px;font-size:16px;color:#0f172a;">Hi {{customer_name}},</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#334155;">We have received your payment — thank you! Your account is up to date.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#166534;border-radius:12px;margin:0 0 20px;">
<tr><td style="padding:22px 24px;text-align:center;">
<div style="font-size:13px;color:#bbf7d0;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:6px;">Amount received</div>
<div style="font-size:30px;color:#ffffff;font-weight:800;">{{currency_symbol}}{{amount_paid}}</div>
<div style="font-size:13px;color:#bbf7d0;margin-top:6px;">Invoice {{invoice_no}} · {{payment_date}} · {{status_label}}</div>
</td></tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dcfce7;border-radius:10px;overflow:hidden;margin:0 0 20px;">
<tr><td style="padding:12px 16px;background:#f0fdf4;font-size:13px;color:#166534;">Invoice</td><td style="padding:12px 16px;font-size:14px;color:#0f172a;font-weight:600;">{{invoice_no}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:13px;color:#166534;">Order</td><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:14px;color:#0f172a;">{{order_number}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:13px;color:#166534;">Amount paid</td><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:14px;color:#0f172a;font-weight:700;">{{currency_symbol}}{{amount_paid}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:13px;color:#166534;">Payment date</td><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:14px;color:#0f172a;">{{payment_date}}</td></tr>
<tr><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:13px;color:#166534;">Balance</td><td style="padding:12px 16px;border-top:1px solid #dcfce7;font-size:14px;color:#0f172a;">{{currency_symbol}}{{balance}}</td></tr>
</table>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:#ffffff;border:1px solid #bbf7d0;border-radius:8px;"><a href="{{invoice_url}}" style="display:inline-block;padding:12px 22px;color:#166534;text-decoration:none;font-weight:600;font-size:14px;">View Receipt</a></td><td style="width:10px;"></td><td style="background:#166534;border-radius:8px;"><a href="{{invoices_url}}" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">My Invoices</a></td></tr></table>
<p style="margin:20px 0 0;font-size:13px;color:#64748b;">Questions about this payment? Contact billing at <a href="mailto:{{company_email}}" style="color:#166534;text-decoration:none;">{{company_email}}</a></p>
<p style="margin:8px 0 0;font-size:13px;color:#64748b;">Thank you for your continued trust in {{app_name}}.</p>
</td></tr>
<tr><td style="padding:16px 32px;background:#f0fdf4;border-top:1px solid #bbf7d0;text-align:center;">
<div style="font-size:12px;color:#166534;line-height:1.6;">{{company_name}} · {{company_address}}<br>{{company_email}} · {{company_phone}}</div>
<div style="font-size:11px;color:#86efac;margin-top:6px;">{{footer_text}}</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
BODY,
        ],

        // ── Service lifecycle ─────────────────────────────────────────────
        [
            'name'    => 'service_activated',
            'subject' => 'Your Service Is Now Active — {{product_name}}',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

Your service has been successfully activated. Here are your service details:

  Service        : {{product_name}}
  Order number   : {{order_number}}
  Domain         : {{domain}}
  Activated on   : {{activation_date}}

{{service_credentials}}

You can manage your service at any time by logging in to your account:
{{app_url}}

If you need assistance getting started, please contact us at {{company_email}} — we are happy to help.

Regards,
The {{app_name}} Team
{{company_name}} · {{company_address}}

BODY,
        ],

        [
            'name'    => 'service_suspended',
            'subject' => 'Important: Your Service Has Been Suspended',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

We are writing to inform you that your service has been suspended.

  Service        : {{product_name}}
  Order number   : {{order_number}}
  Reason         : {{reason}}

To reactivate your service, please settle any outstanding invoices and contact our support team at {{company_email}}.
Services that remain suspended may be subject to cancellation after the grace period.

  Outstanding invoice : {{invoice_no}}
  Pay now            : {{pay_url}}

Please log in to your account to make a payment or contact us if you believe this suspension was made in error.

Regards,
The {{app_name}} Team
{{company_name}} · {{company_address}} · {{company_phone}}

BODY,
        ],

        [
            'name'    => 'service_cancelled',
            'subject' => 'Service Cancellation Confirmed — {{product_name}}',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

We have processed the cancellation of the following service as requested.

  Service      : {{product_name}}
  Order number : {{order_number}}
  Cancelled on : {{cancellation_date}}

Any associated data will be retained for {{data_retention_days}} days following cancellation, after which it will be permanently removed.

If this cancellation was made in error or you would like to reactivate your service, please contact our support team at {{company_email}} as soon as possible.

We are sorry to see you go and hope to serve you again in the future.

Regards,
The {{app_name}} Team

BODY,
        ],

        // ── Support ───────────────────────────────────────────────────────
        [
            'name'    => 'support_ticket_opened',
            'subject' => 'Support Ticket #{{ticket_no}} Opened — We Are On It',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

We have received your support request and it has been assigned ticket number #{{ticket_no}}.

  Ticket number : #{{ticket_no}}
  Subject       : {{subject}}
  Department    : {{department}}

Our team will review your request and respond as soon as possible.

You can reply directly to this email to add to the ticket, or log in to your account to follow it there: {{app_url}}

Regards,
The {{app_name}} Support Team
{{company_name}} · {{company_email}}

BODY,
        ],

        [
            'name'    => 'support_ticket_reply',
            'subject' => 'New Reply on Support Ticket #{{ticket_no}}',
            'status'  => 'active',
            'body'    => <<<'BODY'
Hi {{name}},

There is a new reply on your support ticket.

  Ticket number : #{{ticket_no}}
  Subject       : {{subject}}
  Department    : {{department}}

Reply directly to this email to continue the conversation, or read the full thread in your account: {{app_url}}

Regards,
The {{app_name}} Support Team

BODY,
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::TEMPLATES as $template) {
            DB::table('email_templates')->updateOrInsert(
                ['name' => $template['name']],
                array_merge($template, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
