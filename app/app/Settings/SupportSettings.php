<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Support / ticket settings (legacy `settings` group: support).
 */
class SupportSettings extends Settings
{
    public int $ticket_next_number = 1;

    public string $ticket_prefix = 'TKT-';

    public static function group(): string
    {
        return 'support';
    }

    public static function rules(): array
    {
        return [
            'ticket_next_number' => ['nullable', 'integer', 'min:0'],
            'ticket_prefix' => ['nullable', 'string', 'max:20'],
        ];
    }
}
