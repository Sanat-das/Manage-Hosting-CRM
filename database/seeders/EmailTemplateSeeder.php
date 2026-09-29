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
            'name' => 'welcome',
            'subject' => 'Welcome to {{app_name}} — Your Account Is Ready',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Your account is ready</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">Welcome to {{app_name}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Everything is set up — log in to get started.</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Thank you for choosing {{app_name}}. Your account has been created and is ready to use — you can log in at any time with the button below.</p>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{login_url}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Log In to Your Account &rarr;</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">If you have any questions, our support team is always happy to help at <a href="mailto:{{support_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{support_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
            'name' => 'password_reset',
            'subject' => 'Reset Your {{app_name}} Password',
            'status' => 'inactive',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Password reset</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">Choose a new password</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">This link expires in 60 minutes.</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">We received a request to reset the password for your account. Click the button below to choose a new password.</p>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{reset_link}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Reset Password &rarr;</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">If you did not request a password reset, you can safely ignore this email — your password will not be changed. If you need help, contact us at <a href="mailto:{{support_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{support_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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

        // ── Orders ────────────────────────────────────────────────────────
        [
            'name' => 'order_confirmation',
            'subject' => 'Order {{order_number}} Confirmed — Thank You!',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero: order total ────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Order received</div>
<div style="font-size:48px;font-weight:900;color:#0f172a;letter-spacing:-0.03em;line-height:1.05;">{{currency_symbol}}{{total}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Order {{order_number}}</div>
<div style="margin-top:10px;">
<span style="display:inline-block;background:#ffffff;color:{{primary_color}};border:1px solid #e2e8f0;padding:4px 14px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.06em;">{{status_label}}</span>
</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{customer_name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Thank you for your order. It has been received and is now being processed.</p>
</td></tr>

<!-- ── Order details ────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Product</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{product_name}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Domain</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{domain_name}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Billing Cycle</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{billing_cycle}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;">Invoice</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;">{{invoice_no}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{order_url}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">View Order</a>
</td></tr>

<!-- ── Secondary links ──────────────────────────────────────── -->
<tr><td style="padding:0 40px 28px;text-align:center;">
<a href="{{invoice_url}}" style="color:#475569;text-decoration:none;font-size:13px;margin:0 10px;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;display:inline-block;">View Invoice</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">You will receive a separate email once your service is activated. Quote your order number {{order_number}} when contacting support — <a href="mailto:{{company_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{company_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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

        // ── Billing / Invoices ────────────────────────────────────────────
        [
            'name' => 'invoice_created',
            'subject' => 'Invoice {{invoice_no}} from {{company_name}} — {{currency_symbol}}{{total}} due {{due_date}}',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero: amount due ──────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Amount Due</div>
<div style="font-size:48px;font-weight:900;color:#0f172a;letter-spacing:-0.03em;line-height:1.05;">{{currency_symbol}}{{total}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Invoice {{invoice_no}} &nbsp;·&nbsp; Due {{due_date}}</div>
<div style="margin-top:10px;">
<span style="display:inline-block;background:#ffffff;color:{{primary_color}};border:1px solid #e2e8f0;padding:4px 14px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.06em;">{{status_label}}</span>
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
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{invoice_no}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Order</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{order_number}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice Date</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{invoice_date}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Due Date</td>
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
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
            'name' => 'invoice_overdue_reminder',
            'subject' => 'Action Required: Invoice {{invoice_no}} Is Overdue — {{currency_symbol}}{{balance}} due',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero: amount overdue ──────────────────────────────────── -->
<tr><td style="background:#fef2f2;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#dc2626;margin-bottom:10px;">Overdue notice</div>
<div style="font-size:48px;font-weight:900;color:#0f172a;letter-spacing:-0.03em;line-height:1.05;">{{currency_symbol}}{{balance}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Invoice {{invoice_no}} &nbsp;·&nbsp; Due {{due_date}}</div>
<div style="margin-top:10px;">
<span style="display:inline-block;background:#ffffff;color:#dc2626;border:1px solid #e2e8f0;padding:4px 14px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.06em;">{{status_label}}</span>
</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{customer_name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">This is a reminder that <strong style="color:#0f172a;">Invoice {{invoice_no}}</strong> is now overdue. To avoid suspension of your services, please arrange payment as soon as possible.</p>
</td></tr>

<!-- ── Invoice details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{invoice_no}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Due Date</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#dc2626;border-bottom:1px solid #f1f5f9;">{{due_date}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr style="background:#fef2f2;">
<td style="padding:16px 18px;font-size:13px;font-weight:800;color:#991b1b;">Balance Due</td>
<td style="padding:16px 18px;font-size:20px;font-weight:900;color:#dc2626;">{{currency_symbol}}{{balance}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{pay_url}}" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Pay Now &rarr;</a>
</td></tr>

<!-- ── Secondary links ──────────────────────────────────────── -->
<tr><td style="padding:0 40px 28px;text-align:center;">
<a href="{{invoice_url}}" style="color:#475569;text-decoration:none;font-size:13px;margin:0 10px;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;display:inline-block;">View Invoice</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">If you paid in the last 1–2 business days, please disregard this notice. Questions? Contact our billing team at <a href="mailto:{{company_email}}" style="color:#dc2626;text-decoration:none;font-weight:600;">{{company_email}}</a> or visit our <a href="{{support_url}}" style="color:#dc2626;text-decoration:none;">support centre</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
            'name' => 'payment_received',
            'subject' => 'Payment Received — Thank You! Invoice {{invoice_no}} · {{currency_symbol}}{{amount_paid}}',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero: amount received ─────────────────────────────────── -->
<tr><td style="background:#f0fdf4;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#166534;margin-bottom:10px;">Payment confirmed</div>
<div style="font-size:48px;font-weight:900;color:#0f172a;letter-spacing:-0.03em;line-height:1.05;">{{currency_symbol}}{{amount_paid}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Invoice {{invoice_no}} &nbsp;·&nbsp; {{payment_date}}</div>
<div style="margin-top:10px;">
<span style="display:inline-block;background:#ffffff;color:#166534;border:1px solid #e2e8f0;padding:4px 14px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.06em;">{{status_label}}</span>
</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{customer_name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">We have received your payment — thank you! Your account is up to date.</p>
</td></tr>

<!-- ── Payment details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{invoice_no}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Order</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{order_number}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Payment Date</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{payment_date}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;">Balance</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#334155;">{{currency_symbol}}{{balance}}</td>
</tr>
<tr style="background:#f0fdf4;">
<td style="padding:16px 18px;font-size:13px;font-weight:800;color:#166534;">Amount Paid</td>
<td style="padding:16px 18px;font-size:20px;font-weight:900;color:#166534;">{{currency_symbol}}{{amount_paid}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{invoice_url}}" style="display:inline-block;background:#166534;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">View Receipt &rarr;</a>
</td></tr>

<!-- ── Secondary links ──────────────────────────────────────── -->
<tr><td style="padding:0 40px 28px;text-align:center;">
<a href="{{invoices_url}}" style="color:#475569;text-decoration:none;font-size:13px;margin:0 10px;padding:8px 14px;border:1px solid #e2e8f0;border-radius:8px;display:inline-block;">My Invoices</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">Questions about this payment? Contact our billing team at <a href="mailto:{{company_email}}" style="color:#166534;text-decoration:none;font-weight:600;">{{company_email}}</a>. Thank you for your continued trust in {{app_name}}.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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

        // ── Service lifecycle ─────────────────────────────────────────────
        [
            'name' => 'service_activated',
            'subject' => 'Your Service Is Now Active — {{product_name}}',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Service activated</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">{{product_name}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">{{domain}}</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Your service is now active and ready to use. Here is a summary of the account we set up for you.</p>
</td></tr>

<!-- ── Service details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Domain</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{domain}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;">Activation Date</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;">{{activation_date}}</td>
</tr>
</table>
</td></tr>

<!-- ── Credentials ──────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
{{service_credentials}}
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{login_url}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Log In to Manage Your Service &rarr;</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">If you need help getting started, our support team is happy to help at <a href="mailto:{{company_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{company_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
            'name' => 'service_suspended',
            'subject' => 'Important: Your Service Has Been Suspended',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#fffbeb;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#b45309;margin-bottom:10px;">Service suspended</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">{{product_name}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Order {{order_number}}</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Your service has been suspended. We have not been able to collect payment for the invoice below.</p>
</td></tr>

<!-- ── Service details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Reason</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{reason}}</td>
</tr>
<tr style="background:#fffbeb;">
<td style="padding:16px 18px;font-size:13px;font-weight:800;color:#b45309;">Outstanding Invoice</td>
<td style="padding:16px 18px;font-size:16px;font-weight:900;color:#b45309;">{{invoice_no}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{pay_url}}" style="display:inline-block;background:#b45309;color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Pay Outstanding Invoice &rarr;</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">Once the outstanding balance is settled, your service is reactivated. Services that remain suspended may be cancelled after the grace period. If you believe this suspension was made in error, contact us at <a href="mailto:{{company_email}}" style="color:#b45309;text-decoration:none;font-weight:600;">{{company_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
            'name' => 'service_cancelled',
            'subject' => 'Service Cancellation Confirmed — {{product_name}}',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:#334155;margin-bottom:10px;">Cancellation confirmed</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">{{product_name}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Order {{order_number}}</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">We have processed the cancellation of the service below as requested. We are sorry to see you go.</p>
</td></tr>

<!-- ── Service details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Cancelled On</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{cancellation_date}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;">Data Retention</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#334155;">{{data_retention_days}} days</td>
</tr>
</table>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">Any associated data will be retained for {{data_retention_days}} days following cancellation, after which it will be permanently removed. If this cancellation was made in error or you would like to reactivate your service, contact us at <a href="mailto:{{company_email}}" style="color:#334155;text-decoration:none;font-weight:600;">{{company_email}}</a>.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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

        // ── Support ───────────────────────────────────────────────────────
        [
            'name' => 'support_ticket_opened',
            'subject' => 'Support Ticket #{{ticket_no}} Opened — We Are On It',
            'status' => 'active',
            'body' => <<<'BODY'
Hi {{name}},

Thank you for contacting us. Your support request has been logged and assigned to our team.

  Ticket number : #{{ticket_no}}
  Subject       : {{subject}}
  Department    : {{department}}

A member of our team will review your request and reply as soon as possible. You can add more information by replying directly to this email, or follow the conversation in your account: {{app_url}}

Regards,
The {{app_name}} Support Team
{{company_name}} · {{company_email}}

BODY,
        ],

        [
            'name' => 'support_ticket_reply',
            'subject' => 'New Reply on Support Ticket #{{ticket_no}}',
            'status' => 'active',
            'body' => <<<'BODY'
Hi {{name}},

There is a new reply on your support ticket.

  Ticket number : #{{ticket_no}}
  Subject       : {{subject}}
  Department    : {{department}}

You can continue the conversation by replying directly to this email, or read the full thread in your account: {{app_url}}

Regards,
The {{app_name}} Support Team
{{company_name}} · {{company_email}}

BODY,
        ],

        // ── Live chat ─────────────────────────────────────────────────────
        //
        // The body below is BYTE-IDENTICAL to the one in
        // 2026_09_15_000005_backfill_chat_transcript_email_template, and has to
        // stay that way. Both exist because the in-app updater runs migrations
        // and never seeds, so the migration is the only way the template
        // reaches an upgraded install — and this seeder is the only way it
        // reaches a fresh one.
        //
        // The duplication is deliberate rather than a shared constant: a
        // migration is a historical snapshot and must keep producing what it
        // produced on the day it ran, even after this seeder's wording is
        // edited. What must NOT differ is the two of them today, and this
        // seeder's `updateOrInsert` on `name` runs AFTER the migration on a
        // fresh install — so a divergence here would mean fresh and upgraded
        // installs silently sending different emails.
        //
        // HTML, like order_confirmation and invoice_created: the send path
        // detects it and derives the plain-text alternative itself, so one
        // body covers both. Sends only while
        // `chat_settings.send_transcript_on_close` is on.
        [
            'name' => 'chat_transcript',
            'subject' => 'Your chat with {{company_name}} on {{chat_date}}',
            'status' => 'active',
            'body' => <<<'BODY'
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
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8ecf1;box-shadow:0 2px 16px rgba(15,23,42,0.05);">

<!-- ── Brand header ─────────────────────────────────────────── -->
<tr><td style="background:#ffffff;border-bottom:1px solid #eef2f7;padding:28px 40px;text-align:center;">
<img src="{{app_logo_url}}" alt="{{app_name}}" width="140" style="max-width:140px;height:auto;display:block;margin:0 auto 14px;">
<div style="font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;line-height:1.2;">{{app_name}}</div>
<div style="font-size:11px;color:#94a3b8;margin-top:6px;letter-spacing:0.12em;text-transform:uppercase;">{{company_name}}</div>
</td></tr>

<!-- ── Hero ─────────────────────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Chat transcript</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">Thanks for chatting with us</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">{{chat_date}}</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{customer_name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Thank you for talking to us today. Here is a copy of the conversation for your records.</p>
</td></tr>

<!-- ── Conversation summary ─────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Started</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{chat_started_at}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Closed</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{chat_closed_at}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;">Messages</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;">{{chat_message_count}}</td>
</tr>
</table>
</td></tr>

<!-- ── Transcript ───────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;margin-bottom:8px;">Conversation</div>
{{transcript_html}}
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">Need anything else? Our team is happy to help at <a href="mailto:{{company_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{company_email}}</a> or {{company_phone}}.</p>
</td></tr></table>
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">{{company_name}}</div>
<div style="font-size:12px;color:#64748b;line-height:1.7;">{{company_address}}<br>{{company_email}}<br>{{company_phone}}</div>
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
