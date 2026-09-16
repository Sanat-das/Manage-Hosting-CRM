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
<p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#334155;">Thank you for talking to us today. Here is a copy of the conversation for your records.</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;margin:0 0 20px;">
<tr><td style="padding:16px 20px;">
<div style="font-size:13px;color:#64748b;margin-bottom:6px;">Started</div>
<div style="font-size:14px;color:#0f172a;">{{chat_started_at}}</div>
<div style="margin-top:12px;font-size:13px;color:#64748b;">Messages</div>
<div style="font-size:14px;color:#0f172a;">{{chat_message_count}}</div>
</td></tr>
</table>
{{transcript_html}}
<p style="margin:24px 0 0;font-size:13px;color:#94a3b8;">Need anything else? {{company_email}} &middot; {{company_phone}}</p>
</td></tr>
<tr><td style="padding:18px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
<div style="font-size:12px;color:#94a3b8;line-height:1.6;">{{company_name}} &middot; {{company_address}}<br>{{footer_text}}</div>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
BODY;
    }
};
