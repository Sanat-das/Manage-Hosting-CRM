<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The chat's single settings row.
 *
 * Read through ChatOfficeHours / ChatTranscriptEmailService rather than
 * directly, so the "no row yet" case is answered in one place instead of at
 * every call site. `defaults()` is that answer: a brand-new install and an
 * install whose row was never created must behave identically, and both must
 * behave as the chat did before these settings existed.
 */
#[Fillable([
    'enforce_office_hours', 'timezone', 'require_available_operator', 'closed_message',
    'offline_form_enabled', 'offline_ticket_department', 'send_transcript_on_close',
    'customer_chat_enabled',
])]
class ChatSetting extends Model
{
    /** What the customer is told when the chat is outside its hours. */
    public const DEFAULT_CLOSED_MESSAGE = 'Our team is offline right now. Leave us a message and we will reply by email.';

    protected function casts(): array
    {
        return [
            'enforce_office_hours' => 'boolean',
            'require_available_operator' => 'boolean',
            'offline_form_enabled' => 'boolean',
            'send_transcript_on_close' => 'boolean',
            'customer_chat_enabled' => 'boolean',
        ];
    }

    /**
     * The row, created on first read.
     *
     * firstOrCreate rather than first(): every reader of this table wants an
     * object, and handing them a null to check is how half of them end up not
     * checking. The defaults are the migration's defaults, restated here for
     * the install whose row was deleted.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'enforce_office_hours' => false,
            'timezone' => '',
            'require_available_operator' => false,
            'closed_message' => self::DEFAULT_CLOSED_MESSAGE,
            'offline_form_enabled' => true,
            'offline_ticket_department' => null,
            'send_transcript_on_close' => false,
            'customer_chat_enabled' => true,
        ]);
    }

    /**
     * The timezone the office hours are expressed in.
     *
     * Blank means "the application's", which is the honest default: an admin
     * who has not chosen one has not told us their hours are in anything else.
     */
    public function timezoneName(): string
    {
        $timezone = trim((string) $this->timezone);

        if ($timezone === '') {
            return (string) config('app.timezone', 'UTC');
        }

        return $timezone;
    }

    public function closedMessage(): string
    {
        $message = trim((string) $this->closed_message);

        return $message !== '' ? $message : self::DEFAULT_CLOSED_MESSAGE;
    }
}
