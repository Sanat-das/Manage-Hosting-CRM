<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\Concerns\BuildsEmailVariables;
use App\ViewModels\Admin\OutstandingReceivablesViewModel;
use App\ViewModels\Admin\SupportQueueRiskViewModel;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The weekly "Business Summary": its metrics, their week-over-week
 * comparison and the plain-text body the email carries.
 *
 * Three defects in the old inline command are corrected here:
 *
 * 1. The window was off by one. `created_at >= now()->subDays(7)` counted the
 *    current, incomplete day, and the comparison window was not the mirror
 *    image of it. Both windows are now half-open `[since, until)` and cover
 *    the last N COMPLETE days, ending yesterday.
 * 2. Revenue came from `invoices.status = 'paid' AND paid_at >= since`.
 *    `paid_at` is only written when an invoice reaches 'paid'
 *    (BillingService::markPaid), so every partial payment was silently
 *    dropped. Revenue is now dated by SETTLEMENT, in two terms — see "How
 *    revenue is dated" below.
 * 3. Open tickets were counted with `whereIn('status', ['open','pending'])`.
 *    'pending' no longer exists (migration 2026_09_01_000001 remapped it to
 *    'answered'), so answered / customer_reply / on_hold / in_progress
 *    tickets were all dropped. The house definition is `status <> 'closed'`.
 *
 * How revenue is dated
 * --------------------
 *
 * A payment row does NOT record when the money arrived. The `payments` table
 * carries only `created_at` — no `updated_at`, no `completed_at` — and for an
 * online gateway that timestamp is the moment the customer was REDIRECTED to
 * the gateway: PaymentController writes a `pending` row at checkout, and
 * PaymentSettlementService::settle() later completes that SAME row in place
 * (BillingService::settleOrCreatePayment), so `created_at` keeps pointing at
 * the redirect day. Dating revenue on it books the money on the day the
 * customer was sent away, not the day it arrived.
 *
 * Revenue is therefore dated by settlement, in two mutually exclusive terms:
 *
 *  - Term 1 — a settled invoice (`status = 'paid'`) contributes its total,
 *    dated by `invoices.paid_at`: the moment the invoice was paid. That is
 *    the settlement moment for a gateway payment and the recording moment for
 *    a manual, bank or cash payment. It also covers the invoices that carry
 *    no payment row at all — one marked paid without a ledger entry, or one
 *    imported before the ledger existed — which a payments-ledger report
 *    cannot see.
 *  - Term 2 — an invoice that has NOT settled yet (`status = 'partial'`)
 *    contributes the completed payments recorded against it, dated by each
 *    payment's `created_at`. A partial invoice never gets a `paid_at`
 *    (BillingService only writes it when the status becomes 'paid'), so its
 *    payment rows are the only record of the money already collected.
 *
 * An invoice is in exactly one term — 'paid' or 'partial', never both — so
 * no money is counted twice, and the daily rows are bucketed from the very
 * rows the total was summed from, so the rows and the total cannot disagree.
 *
 * The deliberate trade-off: money collected in window W1 against an invoice
 * that only settles in window W2 is reported in W2, as part of the settled
 * total, and not in W1. Reporting it in W1 would need a per-payment
 * settlement timestamp that the ledger does not carry.
 *
 * The residual inaccuracy no timestamp can fix: an online PARTIAL payment is
 * dated by its redirect day, because a payment row that never settles its
 * invoice has no completion timestamp of its own.
 *
 * The window-level consequence of that, which is not visible from a single
 * report: both windows are computed from CURRENT state, so an online partial
 * payment that was settled in window W1 is reported in the window that
 * contains its REDIRECT date — possibly an EARLIER window than the one the
 * money actually arrived in. The week the money arrived can therefore
 * under-report it, and the redirect week can over-report it.
 *
 * The body is plain text and is built here, in code: there is no admin-managed
 * template for it.
 */
final class BusinessSummaryReport
{
    use BuildsEmailVariables;

    /**
     * Invoice statuses whose money is not collected revenue.
     *
     * The exclusion is by the invoice's CURRENT status: an invoice that
     * currently carries 'refunded', 'void' or 'cancelled' contributes
     * nothing, because it matches neither term of the revenue rule.
     *
     * A refund flips the invoice's status to 'refunded' and credits the
     * customer's wallet without writing a reversing payment row
     * (BillingService::refund), so the money it took has to be excluded by
     * hand. A void or cancelled invoice is NOT necessarily uncollected:
     * BillingService::recordPayment() overwrites the status to 'paid'
     * unconditionally, and PaymentSettlementService::settle() settles money
     * against a void or cancelled invoice on purpose — it records the payment
     * and logs "likely needs a refund" — so money really can have been taken
     * against one. It is excluded because it currently carries that status,
     * and money that was settled against such an invoice is not counted once
     * the invoice has been refunded (status 'refunded').
     *
     * @var array<int, string>
     */
    private const NON_REVENUE_INVOICE_STATUSES = [
        Invoice::STATUS_REFUNDED,
        Invoice::STATUS_VOID,
        Invoice::STATUS_CANCELLED,
    ];

    /**
     * The longest reporting window the report will build. A year is long
     * enough for the job the report does (a weekly summary, or a year-end
     * review) and short enough that the window can never overflow, loop or
     * invert.
     */
    private const MAX_DAYS = 366;

    private const LABEL_WIDTH = 20;

    private const VALUE_WIDTH = 14;

    private const NOTE_WIDTH = 14;

    public readonly int $days;

    /** Inclusive start of the current window — midnight of D-N. */
    public readonly Carbon $periodStart;

    /** Exclusive end of the current window — midnight of today (D0). */
    public readonly Carbon $periodEnd;

    /** Inclusive start of the comparison window — midnight of D-2N. */
    public readonly Carbon $previousPeriodStart;

    /** Exclusive end of the comparison window — midnight of D-N. */
    public readonly Carbon $previousPeriodEnd;

    public readonly Carbon $generatedAt;

    public readonly string $currencySymbol;

    public readonly string $currencyCode;

    public readonly string $reportUrl;

    public readonly float $revenueCollected;

    public readonly float $previousRevenueCollected;

    public readonly string $revenueDelta;

    public readonly int $invoicesSettled;

    public readonly int $previousInvoicesSettled;

    public readonly string $invoicesSettledDelta;

    public readonly float $refundsIssued;

    public readonly float $averageOrderValue;

    public readonly float $previousAverageOrderValue;

    public readonly string $averageOrderValueDelta;

    public readonly int $newCustomers;

    public readonly int $previousNewCustomers;

    public readonly string $newCustomersDelta;

    public readonly int $newOrders;

    public readonly int $previousNewOrders;

    public readonly string $newOrdersDelta;

    public readonly float $newOrderTotal;

    public readonly int $newTickets;

    public readonly int $previousNewTickets;

    public readonly string $newTicketsDelta;

    /**
     * Revenue collected per calendar day of the current window, oldest first.
     *
     * @var array<int, array{date: Carbon, amount: float}>
     */
    public readonly array $dailyRevenue;

    public readonly int $openTickets;

    public readonly int $urgentTickets;

    public readonly int $unassignedTickets;

    public readonly int $oldestUnassignedDays;

    public readonly int $awaitingReplyTickets;

    public readonly float $outstandingTotal;

    public readonly float $overdueTotal;

    public readonly int $overdueCount;

    public function __construct(int $days = 7)
    {
        // The window is clamped in ONE place, here, so the command, the
        // subject line and the period can never disagree: 1 to 366 days. A
        // caller (the command, a test, a future consumer) may pass anything;
        // the report always reports a coherent, non-inverted window.
        $this->days = max(1, min(self::MAX_DAYS, $days));

        $today = Carbon::now()->startOfDay();
        $this->periodStart = $today->copy()->subDays($this->days);
        $this->periodEnd = $today->copy();
        $this->previousPeriodStart = $this->periodStart->copy()->subDays($this->days);
        $this->previousPeriodEnd = $this->periodStart->copy();
        $this->generatedAt = Carbon::now();

        $branding = $this->brandingVariables();
        $this->currencySymbol = (string) ($branding['currency_symbol'] ?? '₹');
        $this->currencyCode = (string) ($branding['currency'] ?? 'INR');
        $this->reportUrl = $this->safeRoute(
            'admin.reports.revenue',
            null,
            rtrim((string) ($branding['app_url'] ?? config('app.url', '')), '/').'/admin/reports/revenue'
        );

        $current = $this->windowMetrics($this->periodStart, $this->periodEnd);
        $previous = $this->windowMetrics($this->previousPeriodStart, $this->previousPeriodEnd);

        $this->revenueCollected = $current['revenue'];
        $this->previousRevenueCollected = $previous['revenue'];
        $this->revenueDelta = self::formatDelta($current['revenue'], $previous['revenue']);

        $this->invoicesSettled = $current['invoices_settled'];
        $this->previousInvoicesSettled = $previous['invoices_settled'];
        $this->invoicesSettledDelta = self::formatDelta($current['invoices_settled'], $previous['invoices_settled']);

        $this->refundsIssued = $current['refunds_issued'];

        $this->averageOrderValue = self::average($current['order_total'], $current['orders']);
        $this->previousAverageOrderValue = self::average($previous['order_total'], $previous['orders']);
        $this->averageOrderValueDelta = self::formatDelta($this->averageOrderValue, $this->previousAverageOrderValue);

        $this->newCustomers = $current['customers'];
        $this->previousNewCustomers = $previous['customers'];
        $this->newCustomersDelta = self::formatDelta($current['customers'], $previous['customers']);

        $this->newOrders = $current['orders'];
        $this->previousNewOrders = $previous['orders'];
        $this->newOrdersDelta = self::formatDelta($current['orders'], $previous['orders']);
        $this->newOrderTotal = $current['order_total'];

        $this->newTickets = $current['tickets'];
        $this->previousNewTickets = $previous['tickets'];
        $this->newTicketsDelta = self::formatDelta($current['tickets'], $previous['tickets']);

        // The daily rows are bucketed from the very rows the window total was
        // summed from, so the two can never disagree.
        $this->dailyRevenue = $this->bucketByDay($current['settled_invoices'], $current['partial_payments']);

        $openTickets = Ticket::query()->where('status', '<>', TicketService::STATUS_CLOSED);
        $this->openTickets = (int) (clone $openTickets)->count();
        $this->urgentTickets = (int) (clone $openTickets)->where('priority', 'urgent')->count();

        $receivables = new OutstandingReceivablesViewModel;
        $this->outstandingTotal = $receivables->outstandingTotal;
        $this->overdueTotal = $receivables->overdueTotal;
        $this->overdueCount = $receivables->overdueCount;

        $support = new SupportQueueRiskViewModel;
        $this->unassignedTickets = $support->unassignedCount;
        $this->oldestUnassignedDays = $support->oldestUnassignedDays;
        $this->awaitingReplyTickets = $support->awaitingReplyCount;
    }

    /**
     * The subject line the email carries.
     */
    public function subject(): string
    {
        return "Business Summary — last {$this->days} days";
    }

    /**
     * Every window-scoped figure for one half-open [since, until) interval.
     *
     * @return array{
     *     revenue: float,
     *     invoices_settled: int,
     *     refunds_issued: float,
     *     customers: int,
     *     orders: int,
     *     order_total: float,
     *     tickets: int,
     *     settled_invoices: Collection<int, Invoice>,
     *     partial_payments: Collection<int, Payment>,
     * }
     */
    private function windowMetrics(Carbon $since, Carbon $until): array
    {
        $settledInvoices = $this->settledInvoices($since, $until);
        $partialPayments = $this->partialPayments($since, $until);

        $revenue = $settledInvoices->sum(fn (Invoice $invoice): float => (float) $invoice->total)
            + $partialPayments->sum(fn (Payment $payment): float => (float) $payment->amount);

        $orders = Order::query()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(total), 0) as orders_total')
            ->first();

        return [
            'revenue' => round((float) $revenue, 2),
            'invoices_settled' => $settledInvoices->count(),
            'refunds_issued' => (float) CustomerWallet::query()
                ->where('type', 'refund')
                ->where('created_at', '>=', $since)
                ->where('created_at', '<', $until)
                ->sum('amount'),
            'customers' => Customer::query()
                ->where('created_at', '>=', $since)
                ->where('created_at', '<', $until)
                ->count(),
            'orders' => (int) ($orders?->orders_count ?? 0),
            'order_total' => (float) ($orders?->orders_total ?? 0),
            'tickets' => Ticket::query()
                ->where('created_at', '>=', $since)
                ->where('created_at', '<', $until)
                ->count(),
            'settled_invoices' => $settledInvoices,
            'partial_payments' => $partialPayments,
        ];
    }

    /**
     * Term 1 — the invoices that SETTLED inside the window.
     *
     * `invoices.paid_at` is written when the invoice reaches 'paid'
     * (BillingService::recordPayment / markPaid), so it is the settlement
     * moment: the day the money was confirmed for a gateway payment, the day
     * it was recorded for a manual, bank or cash payment. It is the timestamp
     * the original report used and it is indexed (invoices_paid_at_index).
     *
     * The exclusion of refunded, void and cancelled invoices happens through
     * the invoice's CURRENT status: the equality filter below admits only
     * `status = 'paid'`, so an invoice that is refunded, void or cancelled
     * matches neither term and contributes nothing. A refund writes no
     * reversing payment row, so the money it took has to be left out by hand.
     *
     * The explicit `whereNotIn` that follows the equality filter is therefore
     * defensive only — it sits BEHIND the equality and can never filter a row
     * by itself — but it is kept so the intent is auditable at the query and
     * not only in this comment.
     *
     * This term also covers a 'paid' invoice that carries no payment row at
     * all — one marked paid without a ledger entry, or imported before the
     * ledger existed — which a payments-ledger report cannot see.
     *
     * @return Collection<int, Invoice>
     */
    private function settledInvoices(Carbon $since, Carbon $until): Collection
    {
        return Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereNotIn('status', self::NON_REVENUE_INVOICE_STATUSES)
            ->where('paid_at', '>=', $since)
            ->where('paid_at', '<', $until)
            ->get(['id', 'total', 'paid_at']);
    }

    /**
     * Term 2 — the money already collected against invoices that have NOT
     * settled yet.
     *
     * A partial invoice never gets a `paid_at` (BillingService only writes it
     * when the status becomes 'paid'), so the settled-invoice query above
     * cannot see the money collected against it; its payment rows are the
     * only record of it. This is the case the original report dropped
     * entirely.
     *
     * The payments table has no index on created_at, so the rows are reached
     * through the `payments.invoice_id` index instead: the (small) set of
     * partial invoices is read first and their payments are fetched by
     * invoice id.
     *
     * That keeps the query off a full scan of `payments` only WHILE the
     * partial-invoice population stays small. Measured against a 200k-row
     * payments table carrying the same indexes, the optimiser uses
     * `payments_invoice_id_index` (type=range) for 20 and 200 ids, but at
     * 2000 and 5000 ids it abandons the index and falls back to type=ALL —
     * a full scan of ~199,503 rows. The guard therefore degrades SILENTLY as
     * the partial population grows, and a hosting business accumulates
     * partially-paid renewal invoices indefinitely. The id list is also
     * loaded into memory unbounded, so a large partial population costs
     * memory as well as query time.
     *
     * The exclusion of refunded, void and cancelled invoices works the same
     * way as in Term 1: the equality filter admits only `status = 'partial'`,
     * so those invoices never reach the payment lookup at all, and the
     * explicit `whereNotIn` behind it is a defensive, auditable restatement
     * of the same rule.
     *
     * @return Collection<int, Payment>
     */
    private function partialPayments(Carbon $since, Carbon $until): Collection
    {
        $partialInvoiceIds = Invoice::query()
            ->where('status', Invoice::STATUS_PARTIAL)
            ->whereNotIn('status', self::NON_REVENUE_INVOICE_STATUSES)
            ->pluck('id');

        if ($partialInvoiceIds->isEmpty()) {
            return new Collection;
        }

        return Payment::query()
            ->whereIn('invoice_id', $partialInvoiceIds)
            ->where('status', 'completed')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->get(['id', 'invoice_id', 'amount', 'created_at']);
    }

    /**
     * Revenue collected per calendar day of the current window.
     *
     * Each row is bucketed on the SAME timestamp its amount was attributed
     * with: a settled invoice on the day it settled (`paid_at`), a payment
     * against an unsettled invoice on the day it was recorded
     * (`created_at`). The rows therefore add up to the window total.
     *
     * @param  Collection<int, Invoice>  $settledInvoices
     * @param  Collection<int, Payment>  $partialPayments
     * @return array<int, array{date: Carbon, amount: float}>
     */
    private function bucketByDay(Collection $settledInvoices, Collection $partialPayments): array
    {
        $totals = [];

        for ($day = $this->periodStart->copy(); $day->lt($this->periodEnd); $day->addDay()) {
            $totals[$day->toDateString()] = 0.0;
        }

        foreach ($settledInvoices as $invoice) {
            $key = Carbon::parse($invoice->paid_at)->toDateString();
            if (array_key_exists($key, $totals)) {
                $totals[$key] += (float) $invoice->total;
            }
        }

        foreach ($partialPayments as $payment) {
            $key = Carbon::parse($payment->created_at)->toDateString();
            if (array_key_exists($key, $totals)) {
                $totals[$key] += (float) $payment->amount;
            }
        }

        $rows = [];
        foreach ($totals as $date => $amount) {
            $rows[] = ['date' => Carbon::parse($date), 'amount' => round($amount, 2)];
        }

        return $rows;
    }

    /**
     * The plain-text body of the email.
     *
     * Named `renderBody()`, not `toPlainText()`: the class pulls in
     * BuildsEmailVariables, which defines `toPlainText(string $html)`. PHP
     * resolves the class method over the trait method, so the two currently
     * coexist, but the signatures are incompatible — a future
     * `$this->toPlainText($html)` call inside this class would be a fatal
     * TypeError.
     */
    public function renderBody(): string
    {
        $lines = [];

        $lines[] = 'BUSINESS SUMMARY — LAST '.$this->days.' DAYS';
        $lines[] = 'Period: '.$this->periodLabel($this->periodStart, $this->periodEnd->copy()->subDay())
            .'   (vs '.$this->periodLabel($this->previousPeriodStart, $this->previousPeriodEnd->copy()->subDay()).')';
        $lines[] = '';
        $lines[] = 'REVENUE';
        $lines[] = $this->row('Revenue collected', $this->money($this->revenueCollected), $this->revenueDelta);
        $lines[] = $this->row('Invoices settled', (string) $this->invoicesSettled, $this->invoicesSettledDelta);
        $lines[] = $this->row('Refunds issued', $this->money($this->refundsIssued));
        $lines[] = $this->row('Average order value', $this->money($this->averageOrderValue), $this->averageOrderValueDelta);
        $lines[] = '';
        $lines[] = 'GROWTH';
        $lines[] = $this->row('New customers', (string) $this->newCustomers, $this->newCustomersDelta);
        $lines[] = $this->row('New orders', (string) $this->newOrders, $this->newOrdersDelta);
        $lines[] = $this->row('New tickets', (string) $this->newTickets, $this->newTicketsDelta);
        $lines[] = '';
        $lines[] = 'DAILY REVENUE';

        foreach ($this->dailyRevenue as $day) {
            $lines[] = $this->row($day['date']->format('d M Y'), $this->money($day['amount']));
        }

        $lines[] = '  '.str_repeat('-', self::LABEL_WIDTH + self::VALUE_WIDTH);
        $lines[] = $this->row('Total', $this->money($this->revenueCollected));
        $lines[] = '';
        $lines[] = 'SUPPORT & COLLECTIONS   (as of '.$this->generatedAt->format('d M Y').')';
        $lines[] = $this->row('Open tickets', (string) $this->openTickets, '(urgent '.$this->urgentTickets.')');
        $lines[] = $this->row('Unassigned', (string) $this->unassignedTickets, '(oldest '.$this->oldestUnassignedDays.'d)');
        $lines[] = $this->row('Awaiting reply', (string) $this->awaitingReplyTickets);
        $lines[] = $this->row('Outstanding', $this->money($this->outstandingTotal));
        $lines[] = $this->row('Overdue', $this->money($this->overdueTotal), '('.$this->overdueCount.' invoices)');
        $lines[] = '';
        $lines[] = 'Generated '.$this->generatedAt->format('d M Y H:i');
        $lines[] = 'Full report: '.$this->reportUrl;

        return implode("\n", $lines);
    }

    /**
     * One period label: a single date when the window covers exactly ONE
     * complete day, otherwise the inclusive `from → to` range.
     *
     * A one-day window is a real window, not an inverted one — it covers the
     * single complete day before today — but printing that day as
     * `12 Nov 2026 → 12 Nov 2026` reads like a bug, so the single day is
     * printed once.
     */
    private function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('d M Y');
        }

        return $from->format('d M Y').' → '.$to->format('d M Y');
    }

    /**
     * One body row: a padded label, a right-aligned value and an optional
     * right-aligned note in its own column.
     */
    private function row(string $label, string $value, string $note = ''): string
    {
        $line = '  '.mb_str_pad($label, self::LABEL_WIDTH).mb_str_pad($value, self::VALUE_WIDTH, ' ', STR_PAD_LEFT);

        if ($note !== '') {
            $line .= mb_str_pad($note, self::NOTE_WIDTH, ' ', STR_PAD_LEFT);
        }

        return rtrim($line);
    }

    private function money(float $value): string
    {
        return $this->currencySymbol.number_format($value, 2);
    }

    /**
     * The week-over-week marker for one figure.
     *
     * No previous value but a current one is 'new'; no movement at all is an
     * em dash; anything else is a signed percentage with one decimal.
     */
    private static function formatDelta(float $current, float $previous): string
    {
        $change = round($current - $previous, 2);

        if ($previous == 0.0) {
            return $current > 0 ? 'new' : '—';
        }

        if ($change == 0.0) {
            return '—';
        }

        $percentage = ($change / $previous) * 100;

        return ($change > 0 ? '▲ ' : '▼ ').number_format(abs($percentage), 1).'%';
    }

    private static function average(float $total, int $count): float
    {
        return $count > 0 ? round($total / $count, 2) : 0.0;
    }
}
