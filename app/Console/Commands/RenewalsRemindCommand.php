<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Billing\RenewalReminderService;
use App\Support\AppSettings;
use App\Support\Logging\AppLog;
use Illuminate\Console\Command;

/**
 * Email upcoming-renewal reminders before the billing run raises the invoice.
 *
 * Thin scheduler wrapper over RenewalReminderService. The window defaults to
 * the `renewal_reminder_days` BillingSettings key and can be overridden per
 * run with --days (used by operators checking the mailer). An order already
 * reminded for its CURRENT next_billing_date is skipped, so the daily cadence
 * sends one reminder per cycle; the admin digest is a daily ops view of every
 * renewal inside the window and is itself limited to one send per day by the
 * service's cache guard, however many times the command is invoked.
 *
 * A non-zero exit on errors is what the Cron Jobs page surfaces as Failed.
 */
class RenewalsRemindCommand extends Command
{
    protected $signature = 'renewals:remind {--days= : Days ahead to remind (defaults to the renewal_reminder_days setting)}';

    protected $description = 'Email clients and admins about upcoming service renewals';

    public function handle(RenewalReminderService $reminders): int
    {
        $option = $this->option('days');
        $days = ($option === null || $option === '')
            ? max(0, (int) AppSettings::get('renewal_reminder_days', '7'))
            : max(0, (int) $option);

        $orders = Order::query()
            ->where('status', Order::STATUS_ACTIVE)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '>=', today())
            ->whereDate('next_billing_date', '<=', today()->addDays($days))
            ->with(['customer.user', 'items.product', 'product'])
            ->orderBy('next_billing_date')
            ->get();

        $reminded = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($orders as $order) {
            // Once per cycle: the marker stores the next_billing_date the last
            // reminder went out for, so an advance (or an admin edit) re-arms it.
            if ($order->renewal_reminder_for !== null
                && $order->next_billing_date !== null
                && $order->renewal_reminder_for->toDateString() === $order->next_billing_date->toDateString()) {
                $skipped++;

                continue;
            }

            try {
                $sent = $reminders->remind($order);

                if ($sent) {
                    $reminded++;
                } else {
                    $errors++;
                }
            } catch (\Throwable $e) {
                $errors++;
                AppLog::cron()->error('renewals:remind failed for one order; run continues.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $recipients = 0;

        if ($orders->isNotEmpty()) {
            try {
                $recipients = $reminders->sendAdminDigest($orders);
            } catch (\Throwable $e) {
                $errors++;
                AppLog::cron()->error('renewals:remind admin digest failed; run continues.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Renewal reminders: {$reminded} sent, {$skipped} already reminded, {$errors} error(s).");

        if ($recipients > 0) {
            $this->info("Admin renewal digest sent to {$recipients} recipient(s).");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
