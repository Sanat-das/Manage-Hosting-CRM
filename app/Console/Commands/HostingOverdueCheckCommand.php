<?php

namespace App\Console\Commands;

use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\ServiceOverdueNotification;
use App\Services\Billing\RenewalReminderService;
use App\Services\HostingEmailService;
use App\Services\HostingService;
use App\Services\NotificationPreferenceService;
use App\Support\AppSettings;
use App\Support\Logging\AppLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Notify staff about hosting accounts past their renewal due date, and
 * suspend them when the money has not arrived.
 *
 * The effective due date is COALESCE(next_due_date, order.next_billing_date),
 * mirroring the admin grid's sort — AND the oldest unpaid invoice of the
 * order. The schedule alone is not enough: the renewal engine advances the
 * service's next billing date when it issues the invoice, so an invoice the
 * customer never paid left the service looking due next year and nobody
 * chased the money.
 *
 * Terminated accounts are ignored. An overdue account raises an in-app
 * notification for every matrix-enabled staff role (notification matrix,
 * service.overdue / database), once per account per due date (the cache key
 * carries the due date, so the next cycle re-arms it), and the staff digest
 * goes out once per day through the service's own once-per-day guard.
 *
 * Suspension is opt-in (`hosting_suspend_on_overdue`) and happens only after
 * `hosting_suspend_after_days` have passed since the due date; the customer
 * is told with the 'service_suspended' template.
 *
 * A non-zero exit on digest failure is what the Cron Jobs page surfaces as
 * Failed.
 */
class HostingOverdueCheckCommand extends Command
{
    protected $signature = 'hosting:overdue-check';

    protected $description = 'Notify staff about hosting accounts past their renewal due date';

    public function handle(RenewalReminderService $reminders, HostingService $hosting, HostingEmailService $hostingEmails): int
    {
        $today = today()->toDateString();

        $overdueAccounts = HostingAccount::query()
            ->where('status', '!=', HostingService::STATUS_TERMINATED)
            ->where(function ($query) use ($today) {
                // The service's own due date...
                $query->whereRaw('COALESCE(next_due_date, (SELECT next_billing_date FROM orders WHERE orders.id = hosting_accounts.order_id)) < ?', [$today])
                    // ...or an invoice the customer has not paid. The renewal
                    // engine advanced the billing schedule when it issued the
                    // invoice, so the schedule on its own says nothing about
                    // the money.
                    ->orWhereExists(function ($sub) use ($today) {
                        $sub->select(DB::raw(1))
                            ->from('invoices')
                            ->whereColumn('invoices.order_id', 'hosting_accounts.order_id')
                            ->whereIn('invoices.status', [Invoice::STATUS_SENT, Invoice::STATUS_OVERDUE, Invoice::STATUS_PARTIAL])
                            ->whereDate('invoices.due_date', '<', $today)
                            ->where(function ($inner) {
                                $inner->where('invoices.paid_amount', '<=', 0)
                                    ->orWhere('invoices.status', Invoice::STATUS_PARTIAL);
                            });
                    });
            })
            ->with(['customer.user:id,first_name,last_name,email', 'product:id,name', 'order:id,next_billing_date,order_number'])
            ->get();

        if ($overdueAccounts->isEmpty()) {
            $this->info('Overdue renewals: none.');

            return self::SUCCESS;
        }

        $staff = $reminders->matrixStaffUsers('service.overdue', 'database')
            ->filter(fn (User $staffUser): bool => app(NotificationPreferenceService::class)->isEnabled($staffUser, 'service.overdue', 'database'));

        $unpaidInvoices = $this->unpaidInvoicesByOrder($today);

        $notifications = 0;
        $suspended = 0;

        foreach ($overdueAccounts as $account) {
            $invoice = $account->order_id !== null ? ($unpaidInvoices[$account->order_id] ?? null) : null;
            $dueDate = $this->effectiveDueDate($account, $invoice?->due_date);

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

            if ($this->shouldSuspend($dueDate, $today)) {
                $suspended += $this->suspendForNonPayment($hosting, $hostingEmails, $account, $dueDate, $invoice);
            }
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

        $this->info("Overdue renewals: {$overdueAccounts->count()} overdue, {$notifications} notification(s) raised, {$suspended} suspended, digest sent to {$recipients} recipient(s).");

        return $digestFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The oldest unpaid invoice of every order that is past due, keyed by
     * order. The invoice is what the customer has not paid; the schedule
     * alone never says that.
     *
     * @return array<int, Invoice>
     */
    private function unpaidInvoicesByOrder(string $today): array
    {
        return Invoice::query()
            ->whereNotNull('order_id')
            ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_OVERDUE, Invoice::STATUS_PARTIAL])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $today)
            ->where(function ($query) {
                $query->where('paid_amount', '<=', 0)
                    ->orWhere('status', Invoice::STATUS_PARTIAL);
            })
            ->orderBy('due_date')
            ->get()
            ->groupBy('order_id')
            ->map(fn ($invoices) => $invoices->first())
            ->all();
    }

    /**
     * The date the account is actually overdue: the service's own due date,
     * or the unpaid invoice, whichever is older.
     */
    private function effectiveDueDate(HostingAccount $account, ?\DateTimeInterface $invoiceDueDate): ?Carbon
    {
        $serviceDue = $account->next_due_date ?? $account->order?->next_billing_date;

        if ($serviceDue === null && $invoiceDueDate === null) {
            return null;
        }

        if ($serviceDue === null) {
            return Carbon::parse($invoiceDueDate)->startOfDay();
        }

        if ($invoiceDueDate === null) {
            return Carbon::parse($serviceDue)->startOfDay();
        }

        $serviceDate = Carbon::parse($serviceDue)->startOfDay();
        $invoiceDate = Carbon::parse($invoiceDueDate)->startOfDay();

        return $serviceDate->lt($invoiceDate) ? $serviceDate : $invoiceDate;
    }

    /**
     * Whether the account may be suspended for non-payment: the switch is on
     * and the grace period has passed.
     */
    private function shouldSuspend(Carbon $dueDate, string $today): bool
    {
        if (! AppSettings::bool('hosting_suspend_on_overdue', false)) {
            return false;
        }

        $graceDays = max(0, (int) AppSettings::get('hosting_suspend_after_days', '7'));

        return $dueDate->addDays($graceDays)->toDateString() <= $today;
    }

    /**
     * Suspend the account and tell the customer why.
     *
     * @return int 1 when the account was suspended, 0 otherwise.
     */
    private function suspendForNonPayment(
        HostingService $hosting,
        HostingEmailService $hostingEmails,
        HostingAccount $account,
        Carbon $dueDate,
        ?Invoice $invoice,
    ): int {
        if ($account->status !== HostingService::STATUS_ACTIVE) {
            return 0;
        }

        $reason = $invoice !== null
            ? "Unpaid invoice {$invoice->invoice_no} (due {$dueDate->toDateString()})"
            : "Renewal due {$dueDate->toDateString()}";

        try {
            $hosting->suspend($account, $reason);
        } catch (\Throwable $e) {
            AppLog::cron()->error('hosting:overdue-check could not suspend the account; run continues.', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        try {
            $hostingEmails->sendSuspended($account, $reason, $invoice?->invoice_no);
        } catch (\Throwable $e) {
            AppLog::cron()->error('hosting:overdue-check suspension email failed; continuing.', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }

        return 1;
    }
}
