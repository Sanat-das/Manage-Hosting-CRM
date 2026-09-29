<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the `chat_transcript` template on installs that will never be seeded.
 *
 * EmailTemplateSeeder is the authority for the template inventory, but the
 * in-app updater runs `php artisan migrate --force` and NEVER `db:seed`. A
 * transcript feature whose template exists only in the seeder would be dead on
 * every upgraded install: ChatTranscriptEmailService looks the row up by name
 * and skips quietly when it is missing, so closing a chat would silently send
 * nothing and log a line nobody reads.
 *
 * Same shape as 2026_09_12_000007_backfill_chat_permissions.php: idempotent,
 * guarded on the table existing, and a no-op on a fresh install because the
 * seeder gets there too (updateOrInsert on `name`, so whichever runs second
 * leaves one row).
 *
 * The row is written `active`, but nothing sends until
 * `chat_settings.send_transcript_on_close` is switched on — the template
 * existing is not the same as the feature being enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $exists = DB::table('email_templates')->where('name', 'chat_transcript')->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('email_templates')->insert([
            'name' => 'chat_transcript',
            'subject' => 'Your chat with {{company_name}} on {{chat_date}}',
            'body' => self::body(),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Not reversed: removing an admin-editable template would throw away any
     * wording the install has since written into it, and there is no record of
     * whether this migration or the seeder created the row.
     */
    public function down(): void
    {
        //
    }

    private static function body(): string
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
BODY;
    }
};
