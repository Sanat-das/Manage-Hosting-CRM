<?php

namespace App\Settings;

use App\Models\TicketDepartment;
use Spatie\LaravelSettings\Settings;

/**
 * SMTP / mail delivery settings (legacy `settings` group: email).
 *
 * The remaining imap_* keys are ticket-email policy only. Per-department
 * mailboxes (Support > Departments) are now the sole inbound path — the old
 * global Incoming Mail host/user/pwd has been removed. `imap_default_department`
 * is the fallback for new tickets, `imap_auto_create_customers` controls
 * guest vs auto-registered customers, and `imap_max_new_tickets_per_hour`
 * caps how fast one sender can open them.
 */
class EmailSettings extends Settings
{
    public string $smtp_host = '';

    public int $smtp_port = 587;

    public string $smtp_username = '';

    public string $smtp_password = '';

    public string $smtp_encryption = 'tls';

    /**
     * Register the sender of an unrecognised email as a customer so their mail
     * can open a ticket.
     *
     * On by default, which is what makes a public sales@ address useful. Turn
     * it off to hold mail from unknown senders for review instead — worth
     * doing if the address attracts spam, because anything that gets past the
     * automated-mail guards creates a user row.
     */
    public bool $imap_auto_create_customers = false;

    /**
     * Fallback department for mail that opens a new ticket when the mailbox
     * itself has no department. Department mailboxes normally use their own
     * slug; blank falls back to the is_default / first enabled department.
     */
    public string $imap_default_department = '';

    /**
     * How many NEW tickets one sender may open by email per hour.
     *
     * Replies to an existing ticket are never throttled — this caps ticket
     * creation, and with it the user/customer rows auto-registration would
     * create. Without a cap, a mail flood is an unbounded write loop: one
     * ticket, one user and one customer per message, every five minutes.
     * Mail over the cap is left unread in the mailbox for a human.
     *
     * 20 is high enough that a genuinely busy customer never trips it.
     */
    public int $imap_max_new_tickets_per_hour = 20;

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
            'imap_auto_create_customers' => ['nullable', 'in:1,0,yes,no,true,false'],
            'imap_max_new_tickets_per_hour' => ['nullable', 'integer', 'between:1,1000'],
            'imap_default_department' => [
                'nullable',
                'string',
                'max:50',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
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
