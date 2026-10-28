<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the renewal reminder templates on installs that will never be seeded.
 *
 * EmailTemplateSeeder is the authority for the template inventory, but the
 * in-app updater runs `php artisan migrate --force` and NEVER `db:seed`. Both
 * renewal templates would therefore be missing on every upgraded install:
 * RenewalReminderService looks each row up by name and skips quietly when it is
 * absent, so the client reminder and the admin digest would send nothing at all.
 *
 * Same shape as 2026_09_15_000005_backfill_chat_transcript_email_template.php:
 * guarded on the table existing, and idempotent per template name so a
 * partially-seeded install converges on the next run. A fresh install is a
 * no-op because the seeder upserts the same keys on `name`.
 *
 * The rows are written `active`, matching the seeder.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const NAMES = ['renewal_reminder', 'renewal_reminder_admin'];

    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $now = now();

        if (! DB::table('email_templates')->where('name', 'renewal_reminder')->exists()) {
            DB::table('email_templates')->insert([
                'name' => 'renewal_reminder',
                'subject' => '{{product_name}} renews on {{renewal_date}} — {{amount_formatted}} due',
                'body' => self::clientBody(),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('email_templates')->where('name', 'renewal_reminder_admin')->exists()) {
            DB::table('email_templates')->insert([
                'name' => 'renewal_reminder_admin',
                'subject' => 'Upcoming service renewals ({{renewal_count}} in the next {{window_days}} days)',
                'body' => self::adminBody(),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Removes only the two rows this migration is responsible for, and only when
     * the table still exists.
     */
    public function down(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        DB::table('email_templates')
            ->whereIn('name', self::NAMES)
            ->delete();
    }

    private static function clientBody(): string
    {
        return <<<'BODY'
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

<!-- ── Hero: renewal date ───────────────────────────────────── -->
<tr><td style="background:#eff6ff;padding:36px 40px;text-align:center;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Renewal reminder</div>
<div style="font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-0.02em;line-height:1.25;">{{product_name}}</div>
<div style="margin-top:14px;font-size:13px;color:#475569;">Renews on {{renewal_date}} ({{days_until_renewal}} days)</div>
</td></tr>

<!-- ── Greeting ─────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<p style="margin:0 0 10px;font-size:18px;font-weight:700;color:#0f172a;line-height:1.3;">Hi {{name}},</p>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">Your service renews on <strong style="color:#0f172a;">{{renewal_date}}</strong> — {{days_until_renewal}} days from today. Here is what will be billed.</p>
</td></tr>

<!-- ── Renewal details ──────────────────────────────────────── -->
<tr><td style="padding:28px 40px 0;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Order No.</td>
<td style="padding:12px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">{{order_number}}</td>
</tr>
<tr>
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #f1f5f9;">Services</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">{{services_list}}</td>
</tr>
<tr style="background:#f8fafc;">
<td style="padding:12px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Renewal Date</td>
<td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #e2e8f0;">{{renewal_date}}</td>
</tr>
<tr style="background:#f0fdf4;">
<td style="padding:16px 18px;font-size:13px;font-weight:800;color:#166534;">Amount</td>
<td style="padding:16px 18px;font-size:20px;font-weight:900;color:#166534;">{{amount_formatted}}</td>
</tr>
</table>
</td></tr>

<!-- ── CTA button ────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 16px;text-align:center;">
<a href="{{order_url}}" style="display:inline-block;background:{{primary_color}};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;letter-spacing:0.01em;">Review Your Service &rarr;</a>
</td></tr>

<!-- ── Note ─────────────────────────────────────────────────── -->
<tr><td style="padding:0 40px 32px;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;"><tr><td style="border-top:1px solid #f1f5f9;padding-top:22px;">
<p style="margin:0;font-size:13px;line-height:1.75;color:#64748b;">No action is needed to continue — the renewal invoice follows shortly before {{renewal_date}}. Questions? Contact us at <a href="mailto:{{support_email}}" style="color:{{primary_color}};text-decoration:none;font-weight:600;">{{support_email}}</a> or review your <a href="{{invoices_url}}" style="color:{{primary_color}};text-decoration:none;">invoices</a>.</p>
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
BODY;
    }

    private static function adminBody(): string
    {
        return <<<'BODY'
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

<!-- ── Intro ────────────────────────────────────────────────── -->
<tr><td style="padding:36px 40px 0;">
<div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:{{primary_color}};margin-bottom:10px;">Renewals due</div>
<p style="margin:0;font-size:14px;line-height:1.75;color:#475569;">{{renewal_count}} active service(s) renew within the next {{window_days}} days. The renewal invoices are raised by the billing run — this is the day's renewal workload.</p>
</td></tr>

<!-- ── Renewals ─────────────────────────────────────────────── -->
<tr><td style="padding:28px 40px 32px;">
{{renewals_html}}
</td></tr>

<!-- ── Footer ───────────────────────────────────────────────── -->
<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
<div style="font-size:12px;color:#64748b;line-height:1.7;">Generated by {{app_name}} · {{company_email}}</div>
<div style="margin-top:10px;font-size:11px;color:#94a3b8;">{{footer_text}}</div>
</td></tr>

</table>
</td></tr>
</table>
</body>
</html>
BODY;
    }
};
