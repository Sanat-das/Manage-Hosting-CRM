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

    public bool $automation_welcome_email = true;

    public bool $automation_invoice_reminders = true;

    public int $automation_invoice_reminder_days = 3;

    public bool $automation_overdue_actions = false;

    public int $automation_suspend_after_due_days = 7;

    public int $automation_terminate_after_due_days = 30;

    public bool $automation_domain_expiry_notices = true;

    public int $automation_domain_expiry_reminder_days = 30;

    public bool $automation_renewal_invoices = true;

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
            'automation_welcome_email' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_invoice_reminders' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_invoice_reminder_days' => ['nullable', 'integer', 'min:0'],
            'automation_overdue_actions' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_suspend_after_due_days' => ['nullable', 'integer', 'min:0'],
            'automation_terminate_after_due_days' => ['nullable', 'integer', 'min:0'],
            'automation_domain_expiry_notices' => ['nullable', 'in:1,0,yes,no,true,false'],
            'automation_domain_expiry_reminder_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'automation_renewal_invoices' => ['nullable', 'in:1,0,yes,no,true,false'],
        ];
    }
}
