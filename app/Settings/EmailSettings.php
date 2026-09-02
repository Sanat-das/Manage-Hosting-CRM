<?php

namespace App\Settings;

use App\Models\TicketDepartment;
use Illuminate\Support\Facades\Request;
use Spatie\LaravelSettings\Settings;

/**
 * SMTP / mail delivery settings (legacy `settings` group: email).
 *
 * The imap_* half is the inbound side: the mailbox `tickets:fetch-mail` polls
 * so customer replies land back on their ticket. It is inert until
 * imap_enabled is on AND imap_host is set — polling a blank host would just
 * log failures every five minutes.
 */
class EmailSettings extends Settings
{
    public string $smtp_host = '';

    public int $smtp_port = 587;

    public string $smtp_username = '';

    public string $smtp_password = '';

    public string $smtp_encryption = 'tls';

    public bool $imap_enabled = false;

    public string $imap_host = '';

    public int $imap_port = 993;

    public string $imap_username = '';

    public string $imap_password = '';

    public string $imap_encryption = 'ssl';

    public string $imap_folder = 'INBOX';

    public bool $imap_validate_cert = true;

    /**
     * Delete fetched mail instead of only flagging it \Seen. Off by default:
     * while a setup is being proven, an un-deleted mailbox is the only way to
     * replay what the parser did with a message.
     */
    public bool $imap_delete_after_fetch = false;

    /**
     * Register the sender of an unrecognised email as a customer so their mail
     * can open a ticket.
     *
     * On by default, which is what makes a public sales@ address useful. Turn
     * it off to hold mail from unknown senders for review instead — worth
     * doing if the address attracts spam, because anything that gets past the
     * automated-mail guards creates a user row.
     */
    public bool $imap_auto_create_customers = true;

    /**
     * Department for mail arriving in the GLOBAL mailbox that opens a new
     * ticket. Department mailboxes always use their own department. Blank
     * falls back to the first enabled department.
     */
    public string $imap_default_department = '';

    public static function group(): string
    {
        return 'email';
    }

    public static function rules(): array
    {
        return [
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'in:tls,ssl,none'],
            'imap_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'imap_host' => ['nullable', 'string', 'max:255'],
            'imap_port' => ['nullable', 'integer', 'between:1,65535'],
            'imap_username' => ['nullable', 'string', 'max:255'],
            'imap_password' => ['nullable', 'string', 'max:255'],
            'imap_encryption' => ['nullable', 'in:ssl,tls,none'],
            'imap_folder' => ['nullable', 'string', 'max:255'],
            'imap_validate_cert' => ['nullable', 'in:1,0,yes,no,true,false'],
            'imap_delete_after_fetch' => ['nullable', 'in:1,0,yes,no,true,false'],
            'imap_auto_create_customers' => ['nullable', 'in:1,0,yes,no,true,false'],
            'imap_default_department' => [
                'nullable',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    // Only imap_enabled installs actually poll a mailbox, so a
                    // stale/disabled slug is harmless while imap is off.
                    if (! Request::boolean('settings.imap_enabled')) {
                        return;
                    }

                    $exists = TicketDepartment::query()->enabled()->where('slug', $value)->exists();

                    if (! $exists) {
                        $fail('The selected default department is not an enabled department.');
                    }
                },
            ],
        ];
    }
}
