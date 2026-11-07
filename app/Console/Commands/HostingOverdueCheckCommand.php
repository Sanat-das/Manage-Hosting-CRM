<?php

namespace App\Console\Commands;

use App\Models\HostingAccount;
use App\Models\User;
use App\Notifications\ServiceOverdueNotification;
use App\Services\Billing\RenewalReminderService;
use App\Services\HostingService;
use App\Services\NotificationPreferenceService;
use App\Support\Logging\AppLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Notify staff about hosting accounts that passed their renewal due date unpaid.
 *
 * Thin scheduler wrapper over RenewalReminderService — the counterpart to
 * renewals:remind for the case the reminder ladder silently drops: an account
 * whose due date has lapsed with no renewal. Each overdue account raises an
 * in-app notification for every matrix-enabled staff role (notification
 * matrix, service.overdue / database), once per account per due date (the
 * cache key carries the due date, so the next cycle re-arms it), and the
 * staff digest goes out once per day through the service's own
 * once-per-day guard.
 *
 * The effective due date is COALESCE(next_due_date, order.next_billing_date),
 * mirroring the admin grid's sort. Terminated accounts are ignored.
 *
 * A non-zero exit on digest failure is what the Cron Jobs page surfaces as
 * Failed.
 */
class HostingOverdueCheckCommand extends Command
{
    protected $signature = 'hosting:overdue-check';

    protected $description = 'Notify staff about hosting accounts past their renewal due date';

    public function handle(RenewalReminderService $reminders): int
    {
        $overdueAccounts = HostingAccount::query()
            ->where('status', '!=', HostingService::STATUS_TERMINATED)
            ->whereRaw('COALESCE(next_due_date, (SELECT next_billing_date FROM orders WHERE orders.id = hosting_accounts.order_id)) < ?', [today()->toDateString()])
            ->with(['customer.user:id,first_name,last_name,email', 'product:id,name', 'order:id,order_number,next_billing_date'])
            ->get();

        if ($overdueAccounts->isEmpty()) {
            $this->info('Overdue renewals: none.');

            return self::SUCCESS;
        }

        $staff = $reminders->matrixStaffUsers('service.overdue', 'database')
            ->filter(fn (User $staffUser): bool => app(NotificationPreferenceService::class)->isEnabled($staffUser, 'service.overdue', 'database'));

        $notifications = 0;

        foreach ($overdueAccounts as $account) {
            $dueDate = $account->next_due_date ?? $account->order?->next_billing_date;

            if ($dueDate === null) {
                continue;
            }

            $dueDateString = $dueDate->toDateString();
            $key = "hosting-overdue-alert:{$account->id}:{$dueDateString}";

            if (Cache::has($key)) {
                continue;
            }

            foreach ($staff as $staffUser) {
                $staffUser->notify(new ServiceOverdueNotification($account, $dueDate));
                $notifications++;
            }

            Cache::put($key, now()->toIso8601String(), now()->addDays(400));
        }

        $digestFailed = false;
        $recipients = 0;

        try {
            $recipients = $reminders->sendAdminOverdueDigest($overdueAccounts);
        } catch (\Throwable $e) {
            $digestFailed = true;

            AppLog::cron()->error('hosting:overdue-check admin digest failed; run continues.', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->info("Overdue renewals: {$overdueAccounts->count()} overdue, {$notifications} notification(s) raised, digest sent to {$recipients} recipient(s).");

        return $digestFailed ? self::FAILURE : self::SUCCESS;
    }
}
