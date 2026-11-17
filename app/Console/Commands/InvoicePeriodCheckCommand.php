<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoicePeriodUntrackedNotification;
use App\Services\Billing\InvoicePeriodAudit;
use App\Services\NotificationPreferenceService;
use App\Support\Logging\AppLog;
use App\Support\Notifications\NotificationMatrix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Find the invoice lines that carry no billing period.
 *
 * A recurring service's schedule is driven by two columns on every invoice
 * line: `billed_due_date` (the period the line bills) and
 * `billed_cycle_months` (that period's length). The duplicate-billing net
 * matches open invoices by `billed_due_date`, and `advanceBillingSchedule()`
 * skips a line without a period — so an invoice whose lines carry no period
 * is invisible to the schedule machinery: it neither blocks a renewal nor
 * advances the schedule when it is paid. The class could hide.
 *
 * This command makes it visible. It reports every open invoice line that
 * claims no period on an order that has a live recurring service, and (with
 * `--fix`) attaches the period to the lines that resolve to exactly one
 * live-recurring order item — the generation pointer moves with it, the
 * pay-by date waits for the money. A line that cannot be attributed
 * unambiguously is reported for a human and never repaired.
 *
 * Without `--fix` nothing is mutated. The staff pool is alerted once per
 * finding per day, through the same matrix and preference gate every other
 * staff notification uses.
 */
class InvoicePeriodCheckCommand extends Command
{
    protected $signature = 'invoices:period-check
                            {--fix : Attach the missing billing period to the findings that resolve to a single live-recurring order item}';

    protected $description = 'Report (and optionally repair) invoice lines that carry no billing period';

    /**
     * The catalogue event that carries the staff alert.
     */
    private const EVENT_KEY = 'invoice.period_untracked';

    public function handle(InvoicePeriodAudit $audit, NotificationPreferenceService $preferences): int
    {
        $report = $audit->scan();

        $findings = $report['findings'];
        $drafts = $report['drafts'];

        if ($findings === [] && $drafts === []) {
            $this->info('Nothing found: no invoice line is missing its billing period.');
            $this->line('Summary: 0 finding(s), 0 repaired, 0 draft invoice(s), 0 unattributable.');

            return self::SUCCESS;
        }

        $this->renderFindings($findings);

        $repaired = 0;
        $remaining = $findings;

        if ((bool) $this->option('fix')) {
            [$repaired, $remaining] = $this->repair($audit, $findings);
        }

        $this->renderDrafts($drafts);

        $notified = 0;
        $notificationFailed = false;

        try {
            $notified = $this->notifyStaff($remaining, $preferences);
        } catch (\Throwable $e) {
            $notificationFailed = true;

            AppLog::cron()->error('invoices:period-check staff notification failed; run continues.', [
                'error' => $e->getMessage(),
            ]);
        }

        $invoiceCount = count(array_unique(array_column($findings, 'invoice_id')));
        $unattributable = count(array_filter($findings, fn (array $finding): bool => ! $finding['repairable']));

        $this->info(sprintf(
            'Summary: %d finding(s) on %d invoice(s), %d repaired, %d draft invoice(s), %d unattributable, %d notification(s) sent.',
            count($findings),
            $invoiceCount,
            $repaired,
            count($drafts),
            $unattributable,
            $notified,
        ));

        return $notificationFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Print one block per finding.
     *
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function renderFindings(array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('Findings — %d invoice line(s) without a billing period:', count($findings)));

        foreach ($findings as $index => $finding) {
            $this->newLine();
            $this->line(sprintf(
                '[%d] %s — order %s',
                $index + 1,
                $finding['invoice_no'],
                $finding['order_no'],
            ));
            $this->line(sprintf('    Customer: %s', $finding['customer_name']));
            $this->line(sprintf(
                '    Status: %s | Total: %s | Due: %s',
                $finding['status'],
                $finding['invoice_total'],
                $finding['due_date'] ?? 'none',
            ));
            $this->line(sprintf('    Line: %s (#%d)', $finding['description'], $finding['invoice_item_id']));

            if ($finding['repairable']) {
                $this->line(sprintf(
                    '    Should bill: order item #%d %s',
                    $finding['order_item_id'],
                    $finding['order_item_name'],
                ));
                $this->line(sprintf(
                    '    Period that would be attached: %s (%d months)',
                    $finding['period_due_date'],
                    $finding['cycle_months'],
                ));
                $this->line('    Repair: automatic');

                continue;
            }

            $this->line('    Should bill: UNATTRIBUTABLE — a human must pick the order item');
            $this->line('    Period that would be attached: unknown');
            $this->line(sprintf('    Repair: manual only — %s', $finding['reason']));
        }
    }

    /**
     * Print the drafts: never sent, so not an open demand, but the same
     * shape is worth a look before the invoice goes out.
     *
     * @param  array<int, array<string, mixed>>  $drafts
     */
    private function renderDrafts(array $drafts): void
    {
        if ($drafts === []) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('Drafts — not raised to the customer (%d):', count($drafts)));

        foreach ($drafts as $draft) {
            $this->line(sprintf(
                '  %s (order %s) — %s — %d line(s) without a billing period',
                $draft['invoice_no'],
                $draft['order_no'],
                $draft['customer_name'],
                $draft['lines'],
            ));
        }
    }

    /**
     * Repair every finding that resolves to a single live-recurring order
     * item. The others are left alone for a human.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return array{0: int, 1: array<int, array<string, mixed>>} The number of
     *                                                            repairs and the findings that are still outstanding.
     */
    private function repair(InvoicePeriodAudit $audit, array $findings): array
    {
        $repaired = 0;
        $remaining = [];

        if ($findings !== []) {
            $this->newLine();
            $this->line('Repairs:');
        }

        foreach ($findings as $finding) {
            if (! $finding['repairable']) {
                $remaining[] = $finding;

                $this->line(sprintf('  Not repaired: %s — %s', $finding['invoice_no'], $finding['reason']));

                continue;
            }

            $result = $audit->repair($finding['invoice_id'], $finding['invoice_item_id'], $finding['order_item_id']);

            if ($result === null) {
                $remaining[] = $finding;

                $this->line(sprintf('  Not repaired: %s — the finding is no longer repairable', $finding['invoice_no']));

                continue;
            }

            $repaired++;

            $this->line(sprintf(
                '  Repaired: %s — period %s (%d months) attached to order item #%d, next invoice date %s → %s',
                $result['invoice_no'],
                $result['billed_due_date'],
                $result['billed_cycle_months'],
                $result['order_item_id'],
                $result['next_invoice_date_before'],
                $result['next_invoice_date_after'],
            ));
        }

        return [$repaired, $remaining];
    }

    /**
     * Tell the staff pool about the findings that are still outstanding.
     *
     * Same path as every other staff notification: the matrix pool, the
     * per-user preference gate, and a cache guard so the same finding is not
     * alerted twice in a day.
     *
     * @param  array<int, array<string, mixed>>  $findings
     * @return int The number of notifications sent.
     */
    private function notifyStaff(array $findings, NotificationPreferenceService $preferences): int
    {
        if ($findings === []) {
            return 0;
        }

        $staff = NotificationMatrix::staffPool(self::EVENT_KEY, 'database')
            ->filter(fn (User $staffUser): bool => $preferences->isEnabled($staffUser, self::EVENT_KEY, 'database'));

        if ($staff->isEmpty()) {
            return 0;
        }

        // One notification per invoice, carrying the findings that are new
        // since the last alert.
        $newByInvoice = [];

        foreach ($findings as $finding) {
            if (Cache::has($this->dedupKey($finding))) {
                continue;
            }

            $newByInvoice[$finding['invoice_id']][] = $finding;
        }

        $sent = 0;

        foreach ($newByInvoice as $invoiceFindings) {
            $invoice = Invoice::with('customer')->find($invoiceFindings[0]['invoice_id']);

            if ($invoice === null) {
                continue;
            }

            foreach ($staff as $staffUser) {
                $staffUser->notify(new InvoicePeriodUntrackedNotification($invoice, count($invoiceFindings)));
                $sent++;
            }

            foreach ($invoiceFindings as $finding) {
                Cache::put($this->dedupKey($finding), now()->toIso8601String(), now()->addDay());
            }
        }

        return $sent;
    }

    /**
     * The cache key that keeps one finding from being alerted twice in a day.
     *
     * @param  array<string, mixed>  $finding
     */
    private function dedupKey(array $finding): string
    {
        return 'invoice-period-untracked:'.$finding['invoice_id'].':'.$finding['invoice_item_id'];
    }
}
