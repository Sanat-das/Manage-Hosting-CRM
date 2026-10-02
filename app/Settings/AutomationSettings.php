<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Automation / workflow settings (new T4.2 group: automation).
 */
class AutomationSettings extends Settings
{
    public bool $automation_workflows_enabled = true;

    public string $automation_default_workflow = '';

    public bool $automation_auto_close_tickets = false;

    public int $automation_auto_close_ticket_days = 5;

    public bool $automation_invoice_reminders = true;

    public int $automation_invoice_reminder_days = 3;

    public static function group(): string
    {
        return 'automation';
    }

    public static function rules(): array
    {
        return [
            'automation_workflows_enabled' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_default_workflow' => ['nullable', 'string', 'max:100'],
            'automation_auto_close_tickets' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_auto_close_ticket_days' => ['nullable', 'integer', 'min:0'],
            'automation_invoice_reminders' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_invoice_reminder_days' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
