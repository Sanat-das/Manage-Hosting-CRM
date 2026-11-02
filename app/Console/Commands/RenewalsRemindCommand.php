<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\RenewalReminderLog;
use App\Services\Billing\RenewalReminderService;
use App\Support\AppSettings;
use App\Support\Logging\AppLog;
use Illuminate\Console\Command;

/**
 * Email upcoming-renewal reminders before the billing run raises the invoice.
 *
 * Thin scheduler wrapper over RenewalReminderService. The reminder LADDER
 * defaults to the `renewal_reminder_days` BillingSettings key — comma-separated
 * lead days such as `30,15,7`, one reminder per rung per billing cycle — and
 * can be overridden per run with --days (a single rung, used by operators
 * checking the mailer).
 *
 * Each run resolves, per order, which rungs of the current cycle have passed.
 * A rung that has already been recorded — sent or suppressed — is skipped, so
 * the daily cadence sends each rung exactly once. When several rungs passed
 * while the command was not running (a late deploy, a paused cron), only the
 * most recent passed rung is sent; the older ones are recorded as suppressed
 * so the client never receives a burst of stale reminders. The admin digest is
 * a daily ops view of the orders reminded in the run and is itself limited to
 * one send per day by the service's cache guard, however many times the
 * command is invoked.
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
        $ladder = ($option === null || $option === '')
            ? RenewalReminderService::parseOffsets((string) AppSettings::get('renewal_reminder_days', '7'))
            : [max(0, (int) $option)];

        if ($ladder === []) {
            $this->error('Renewal reminders: the configured lead-day ladder is empty; nothing to send.');

            return self::FAILURE;
        }

        $orders = Order::query()
            ->where('status', Order::STATUS_ACTIVE)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '>=', today())
            ->whereDate('next_billing_date', '<=', today()->addDays(max($ladder)))
            ->with(['customer.user', 'items.product', 'product', 'reminderLogs'])
            ->orderBy('next_billing_date')
            ->get();

        $reminded = 0;
        $skipped = 0;
        $errors = 0;
        $digestOrders = [];
        $today = today()->toDateString();

        foreach ($orders as $order) {
            $cycle = $order->next_billing_date->toDateString();

            $logged = $order->reminderLogs
                ->filter(fn (RenewalReminderLog $log): bool => $log->cycle_date?->toDateString() === $cycle)
                ->map(fn (RenewalReminderLog $log): int => (int) $log->days_before)
                ->all();

            // Upgrade safety: a cycle already covered by the legacy single-slot
            // marker has no ladder rows yet. Skipping it here keeps the first
            // ladder run from re-counting, and digesting, a reminder the old
            // code already sent; the service keeps the same guard for direct
            // callers.
            if ($logged === []
                && $order->renewal_reminder_for !== null
                && $order->renewal_reminder_for->toDateString() === $cycle) {
                $skipped++;

                continue;
            }

            $passed = array_values(array_filter(
                $ladder,
                fn (int $offset): bool => $order->next_billing_date->copy()->subDays($offset)->toDateString() <= $today,
            ));

            $pending = array_values(array_diff($passed, $logged));

            if ($pending === []) {
                $skipped++;

                continue;
            }

            // Smallest offset = the rung closest to the due date: the one a
            // client should actually receive. Everything older is stale.
            sort($pending);
            $target = (int) array_shift($pending);

            try {
                foreach ($pending as $stale) {
                    RenewalReminderLog::firstOrCreate(
                        [
                            'order_id' => $order->id,
                            'cycle_date' => $cycle,
                            'days_before' => $stale,
                        ],
                        ['sent_at' => null],
                    );
                }

                $sent = $reminders->remind($order, $target);

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

            // Attempted counts for the digest even when the send failed: ops
            // must see the order the run tried to remind.
            $digestOrders[] = $order;
        }

        $recipients = 0;

        if ($digestOrders !== []) {
            try {
                $recipients = $reminders->sendAdminDigest(collect($digestOrders));
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
