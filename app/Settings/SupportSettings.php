<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Support / ticket settings (legacy `settings` group: support).
 *
 * `ticket_next_number` is NOT here, and must not be added back. The counter is
 * allocated under `lockForUpdate()` on a real row by
 * TicketService::lockedCounterRow(), which spatie's settings repository cannot
 * provide, so it stays an untyped legacy `settings` row (see
 * AppSettings::keyToSection()). Declaring it here as well is what let the page
 * and TicketService drift to 1 vs 18.
 */
class SupportSettings extends Settings
{
    public string $ticket_prefix = 'TKT-';

    public static function group(): string
    {
        return 'support';
    }

    public static function rules(): array
    {
        return [
            'ticket_prefix' => ['nullable', 'string', 'max:20'],
        ];
    }
}
