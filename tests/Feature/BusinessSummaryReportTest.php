<?php

namespace Tests\Feature;

use App\Jobs\SendEmail;
use App\Models\Customer;
use App\Models\CustomerWallet;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\BusinessSummaryReport;
use App\Settings\CatalogSettings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Behaviour of the weekly "Business Summary" report.
 *
 * The defects this slice fixes are the point of most of these tests: a
 * partial payment used to be invisible to the revenue figure, revenue used to
 * be dated on the payments ledger's created_at — the REDIRECT day of an
 * online payment, not the day the money was confirmed — an off-by-one window
 * used to include the current, incomplete day, and the open-ticket count used
 * to filter on a status ('pending') that no longer exists.
 */
class BusinessSummaryReportTest extends TestCase
{
    use RefreshDatabase;

    private int $invoiceSequence = 0;

    private int $orderSequence = 0;

    private int $ticketSequence = 0;

    private ?Product $product = null;

    // ------------------------------------------------------------ revenue

    public function test_partial_payment_inside_the_window_counts_towards_revenue_collected(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PARTIAL,
            'total' => 500.00,
            'paid_amount' => 250.00,
        ]);
        $this->makePayment($invoice, 250.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        // A 'partial' invoice never gets a paid_at, so the old
        // `status = 'paid' AND paid_at >= since` query saw nothing at all.
        $this->assertNull($invoice->fresh()->paid_at);
        $this->assertSame(250.00, $report->revenueCollected);
        $this->assertSame(0, $report->invoicesSettled);

        // It is dated by the payment row — the only timestamp it has.
        $days = collect($report->dailyRevenue)->keyBy(fn (array $row): string => $row['date']->toDateString());
        $this->assertSame(250.00, $days[now()->subDays(2)->toDateString()]['amount']);
    }

    public function test_completed_payment_on_a_refunded_invoice_is_not_counted(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_REFUNDED,
            'total' => 400.00,
            'paid_amount' => 400.00,
            'paid_at' => now()->subDays(2),
        ]);
        $this->makePayment($invoice, 400.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(0.0, $report->revenueCollected);
    }

    /**
     * A void or cancelled invoice was never collected, so neither of the two
     * revenue terms may pick it up.
     */
    public function test_a_void_or_cancelled_invoice_is_not_counted(): void
    {
        $customer = $this->makeCustomer();

        $void = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_VOID,
            'total' => 200.00,
            'paid_at' => now()->subDays(2),
        ]);
        $this->makePayment($void, 200.00, now()->subDays(2));

        $cancelled = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_CANCELLED,
            'total' => 150.00,
            'paid_at' => now()->subDays(2),
        ]);
        $this->makePayment($cancelled, 150.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(0.0, $report->revenueCollected);
    }

    public function test_settled_invoice_without_payment_rows_is_counted_as_revenue(): void
    {
        $customer = $this->makeCustomer();
        $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PAID,
            'total' => 900.00,
            'paid_amount' => 900.00,
            'paid_at' => now()->subDays(3),
        ]);

        $report = new BusinessSummaryReport(7);

        // Imported/legacy invoices carry a paid_at but no ledger rows.
        $this->assertSame(900.00, $report->revenueCollected);
        $this->assertSame(1, $report->invoicesSettled);
    }

    public function test_invoice_with_both_a_payment_row_and_a_paid_at_is_counted_once(): void
    {
        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PAID,
            'total' => 300.00,
            'paid_amount' => 300.00,
            'paid_at' => now()->subDays(2),
        ]);
        $this->makePayment($invoice, 300.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(300.00, $report->revenueCollected);
    }

    /**
     * The gateway regression: revenue must land on the day the money was
     * CONFIRMED, not on the day the customer was redirected to the gateway.
     *
     * The row is written as 'pending' at the redirect (PaymentController),
     * then completed IN PLACE when the gateway confirms the money
     * (PaymentSettlementService -> BillingService::recordPayment). Nothing
     * rewrites its created_at, so a report dated on the ledger's created_at
     * books the money on the redirect day — a week early here.
     */
    public function test_a_gateway_payment_is_dated_to_its_settlement_not_to_the_redirect(): void
    {
        $redirectedAt = Carbon::parse('2026-06-01 09:00:00');
        $this->travelTo($redirectedAt);

        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_SENT,
            'total' => 500.00,
        ]);

        // The redirect: a PENDING payment row, dated at the redirect.
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 500.00,
            'method' => 'razorpay',
            'gateway_id' => '1',
            'transaction_id' => 'pay_ref_123',
            'status' => 'pending',
        ]);
        $this->backdate($payment, $redirectedAt);

        // The gateway confirms the money on 10 Jun.
        $this->travelTo(Carbon::parse('2026-06-10 10:00:00'));

        app(BillingService::class)->recordPayment(
            (int) $invoice->id,
            500.00,
            'razorpay',
            'txn_abc',
            (int) $payment->id,
        );

        // The report runs on 12 Jun: its window is [05 Jun, 12 Jun).
        $this->travelTo(Carbon::parse('2026-06-12 08:00:00'));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(500.00, $report->revenueCollected);
        $this->assertSame(1, $report->invoicesSettled);

        // The settlement moved the INVOICE; the payment row kept its
        // redirect timestamp.
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('2026-06-01 09:00:00', $payment->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('paid', $invoice->fresh()->status);

        // And the money is on the settlement day, not the redirect day.
        $days = collect($report->dailyRevenue)->keyBy(fn (array $row): string => $row['date']->toDateString());
        $this->assertSame(500.00, $days['2026-06-10']['amount']);
        $this->assertArrayNotHasKey('2026-06-01', $days->all());
    }

    public function test_the_daily_revenue_rows_sum_to_the_window_total(): void
    {
        $customer = $this->makeCustomer();

        $partial = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PARTIAL,
            'total' => 500.00,
            'paid_amount' => 250.00,
        ]);
        $this->makePayment($partial, 250.00, now()->subDays(2));

        $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PAID,
            'total' => 900.00,
            'paid_amount' => 900.00,
            'paid_at' => now()->subDays(4),
        ]);

        $report = new BusinessSummaryReport(7);

        $this->assertSame(1150.00, $report->revenueCollected);

        // The rows are bucketed from the very rows the total was summed from.
        $this->assertSame(
            $report->revenueCollected,
            round(array_sum(array_column($report->dailyRevenue, 'amount')), 2)
        );

        $days = collect($report->dailyRevenue)->keyBy(fn (array $row): string => $row['date']->toDateString());
        $this->assertSame(250.00, $days[now()->subDays(2)->toDateString()]['amount']);
        $this->assertSame(900.00, $days[now()->subDays(4)->toDateString()]['amount']);

        // The printed total and the printed revenue figure agree.
        $body = $report->renderBody();
        $this->assertSame(2, substr_count($body, $report->currencySymbol.'1,150.00'));
    }

    /**
     * The documented trade-off: money collected against an invoice that only
     * settles in a LATER window is reported in that later window, so it is
     * never counted in both.
     */
    public function test_an_invoice_paid_partially_in_one_window_and_settled_in_a_later_window_is_not_counted_in_both(): void
    {
        $customer = $this->makeCustomer();

        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PARTIAL,
            'total' => 500.00,
            'paid_amount' => 250.00,
        ]);
        $this->makePayment($invoice, 250.00, now()->subDays(10));

        // The invoice settles inside the current window.
        $invoice->update([
            'status' => Invoice::STATUS_PAID,
            'paid_amount' => 500.00,
            'paid_at' => now()->subDays(3),
        ]);

        $report = new BusinessSummaryReport(7);

        // The settled total is booked in the current window only.
        $this->assertSame(500.00, $report->revenueCollected);
        $this->assertSame(0.00, $report->previousRevenueCollected);
    }

    // ------------------------------------------------------------- window

    public function test_window_covers_only_the_last_seven_complete_days(): void
    {
        $customer = $this->makeCustomer();

        $tooOld = $this->makeInvoice($customer);
        $this->makePayment($tooOld, 100.00, now()->subDays(8)->startOfDay());

        $today = $this->makeInvoice($customer);
        $this->makePayment($today, 700.00, now());

        $yesterday = $this->makeInvoice($customer);
        $this->makePayment($yesterday, 300.00, now()->subDay()->startOfDay());

        $report = new BusinessSummaryReport(7);

        $this->assertSame(300.00, $report->revenueCollected);
        $this->assertCount(7, $report->dailyRevenue);
    }

    // -------------------------------------------------------- support queue

    public function test_open_tickets_counts_every_status_except_closed(): void
    {
        $customer = $this->makeCustomer();

        foreach (['open', 'answered', 'customer_reply', 'on_hold', 'in_progress'] as $status) {
            $this->makeTicket($customer, $status);
        }
        $this->makeTicket($customer, 'closed');
        $this->makeTicket($customer, 'open', 'urgent');
        $this->makeTicket($customer, 'closed', 'urgent');

        $report = new BusinessSummaryReport(7);

        $this->assertSame(6, $report->openTickets);
        $this->assertSame(1, $report->urgentTickets);
    }

    // -------------------------------------------------------------- growth

    public function test_average_order_value_is_the_order_total_divided_by_the_order_count(): void
    {
        $customer = $this->makeCustomer();

        $this->makeOrder($customer, 100.00, now()->subDays(3));
        $this->makeOrder($customer, 200.00, now()->subDays(1));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(2, $report->newOrders);
        $this->assertSame(300.00, $report->newOrderTotal);
        $this->assertSame(150.00, $report->averageOrderValue);
    }

    public function test_refunds_issued_sums_the_wallet_refund_rows_inside_the_window(): void
    {
        $customer = $this->makeCustomer();

        $this->makeWalletEntry($customer, 'refund', 75.00, now()->subDays(2));
        $this->makeWalletEntry($customer, 'refund', 25.00, now()->subDays(8));
        $this->makeWalletEntry($customer, 'credit', 500.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(75.00, $report->refundsIssued);
    }

    // -------------------------------------------------------- week over week

    public function test_week_over_week_delta_reports_the_change_against_the_previous_window(): void
    {
        $customer = $this->makeCustomer();

        $previous = $this->makeInvoice($customer);
        $this->makePayment($previous, 100.00, now()->subDays(10));

        $current = $this->makeInvoice($customer);
        $this->makePayment($current, 150.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(150.00, $report->revenueCollected);
        $this->assertSame(100.00, $report->previousRevenueCollected);
        $this->assertSame('▲ 50.0%', $report->revenueDelta);
        $this->assertMatchesRegularExpression('/Revenue collected\s+.*150\.00\s+▲ 50\.0%/u', $report->renderBody());
    }

    public function test_a_figure_without_a_previous_value_renders_new(): void
    {
        $customer = $this->makeCustomer();
        $this->backdate($customer, now()->subDays(2));

        $report = new BusinessSummaryReport(7);

        $this->assertSame(1, $report->newCustomers);
        $this->assertSame(0, $report->previousNewCustomers);
        $this->assertSame('new', $report->newCustomersDelta);
        $this->assertMatchesRegularExpression('/New customers\s+1\s+new/u', $report->renderBody());
    }

    // ------------------------------------------------------------ rendering

    public function test_rendered_body_contains_the_sections_the_daily_rows_and_the_currency_symbol(): void
    {
        app(CatalogSettings::class)->fill(['catalog_currency_symbol' => '€'])->save();

        $customer = $this->makeCustomer();
        $invoice = $this->makeInvoice($customer, [
            'status' => Invoice::STATUS_PARTIAL,
            'total' => 120.00,
        ]);
        $this->makePayment($invoice, 120.00, now()->subDays(2));

        $report = new BusinessSummaryReport(7);
        $body = $report->renderBody();

        $this->assertSame('€', $report->currencySymbol);
        $this->assertStringContainsString('BUSINESS SUMMARY — LAST 7 DAYS', $body);
        $this->assertStringContainsString('REVENUE', $body);
        $this->assertStringContainsString('GROWTH', $body);
        $this->assertStringContainsString('DAILY REVENUE', $body);
        $this->assertStringContainsString('SUPPORT & COLLECTIONS', $body);
        $this->assertStringContainsString('Full report:', $body);
        $this->assertMatchesRegularExpression('/'.now()->subDays(2)->format('d M Y').'\s+€120\.00/u', $body);
        $this->assertStringContainsString('Total', $body);
    }

    /**
     * A one-day window covers exactly one complete day, so the period is
     * printed as a SINGLE date instead of the same date twice. The window
     * itself is not inverted — it is strictly before — but the range form
     * reads like a bug.
     */
    public function test_a_one_day_window_renders_the_period_as_a_single_date(): void
    {
        $report = new BusinessSummaryReport(1);

        $body = $report->renderBody();

        $periodLine = collect(explode("\n", $body))
            ->first(fn (string $line): bool => str_starts_with($line, 'Period:'));

        $this->assertNotNull($periodLine);
        $this->assertStringNotContainsString('→', $periodLine);
        $this->assertStringContainsString('Period: '.$report->periodStart->format('d M Y'), $periodLine);

        // The comparison window is the single previous day, so it is one
        // date too.
        $this->assertStringContainsString('(vs '.$report->previousPeriodStart->format('d M Y').')', $periodLine);
    }

    /**
     * Any window longer than one day keeps the inclusive `from → to` form.
     */
    public function test_a_multi_day_window_renders_the_period_as_a_range(): void
    {
        $report = new BusinessSummaryReport(7);

        $body = $report->renderBody();

        $this->assertStringContainsString(
            'Period: '.$report->periodStart->format('d M Y').' → '.$report->periodEnd->copy()->subDay()->format('d M Y'),
            $body
        );
        $this->assertStringContainsString(
            '(vs '.$report->previousPeriodStart->format('d M Y').' → '.$report->previousPeriodEnd->copy()->subDay()->format('d M Y').')',
            $body
        );
    }

    // --------------------------------------------------------------- command

    public function test_dry_run_prints_the_report_and_dispatches_no_email(): void
    {
        Queue::fake();

        $exitCode = Artisan::call('reports:send-scheduled', [
            '--days' => 7,
            '--to' => ['ops@example.com'],
            '--dry-run' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('BUSINESS SUMMARY — LAST 7 DAYS', $output);
        $this->assertStringContainsString('DAILY REVENUE', $output);
        $this->assertStringContainsString('SUPPORT & COLLECTIONS', $output);

        Queue::assertNotPushed(SendEmail::class);
    }

    /**
     * The window is clamped to 1..366 days, so a hostile --days can neither
     * exhaust memory nor invert the period.
     */
    public function test_the_days_option_is_clamped_to_a_coherent_window(): void
    {
        Queue::fake();

        $cases = [
            0 => 1,
            'abc' => 1,
            2147483647 => 366,
            '99999999999999999999' => 366,
        ];

        foreach ($cases as $option => $expected) {
            $exitCode = Artisan::call('reports:send-scheduled', [
                '--days' => $option,
                '--to' => ['ops@example.com'],
                '--dry-run' => true,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exitCode, '--days='.$option);
            $this->assertStringContainsString('BUSINESS SUMMARY — LAST '.$expected.' DAYS', $output, '--days='.$option);

            // The printed period runs forwards, never backwards. A one-day
            // window covers the single complete day before today and is
            // printed as ONE date; a longer window prints the inclusive
            // range.
            $this->assertSame(1, preg_match('/Period: (\d{2} \w{3} \d{4})(?: → (\d{2} \w{3} \d{4}))?/u', $output, $matches), $output);

            if (isset($matches[2]) && $matches[2] !== '') {
                $this->assertTrue(
                    Carbon::parse($matches[1])->lte(Carbon::parse($matches[2])),
                    'The period must not be inverted for --days='.$option.': '.$output
                );
            }
        }
    }

    public function test_the_report_itself_clamps_the_window_to_a_year(): void
    {
        $report = new BusinessSummaryReport(2147483647);

        $this->assertSame(366, $report->days);
        $this->assertCount(366, $report->dailyRevenue);
        $this->assertTrue($report->periodStart->lt($report->periodEnd));

        $oneDay = new BusinessSummaryReport(0);

        $this->assertSame(1, $oneDay->days);
        $this->assertCount(1, $oneDay->dailyRevenue);
        $this->assertTrue($oneDay->periodStart->lt($oneDay->periodEnd));
    }

    // --------------------------------------------------------------- helpers

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }

    private function makeInvoice(Customer $customer, array $attributes = []): Invoice
    {
        $this->invoiceSequence++;

        return Invoice::create(array_merge([
            'invoice_no' => 'INV-'.str_pad((string) $this->invoiceSequence, 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'total' => 100.00,
            'status' => Invoice::STATUS_PARTIAL,
            'due_date' => now()->addDays(10)->toDateString(),
        ], $attributes));
    }

    private function makePayment(Invoice $invoice, float $amount, Carbon $createdAt): Payment
    {
        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'method' => 'cash',
            'status' => 'completed',
        ]);

        return $this->backdate($payment, $createdAt);
    }

    private function makeOrder(Customer $customer, float $total, Carbon $createdAt): Order
    {
        $this->orderSequence++;

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $this->makeProduct()->id,
            'order_number' => 'ORD-'.str_pad((string) $this->orderSequence, 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $total,
            'status' => Order::STATUS_ACTIVE,
        ]);

        return $this->backdate($order, $createdAt);
    }

    private function makeWalletEntry(Customer $customer, string $type, float $amount, Carbon $createdAt): CustomerWallet
    {
        $entry = CustomerWallet::create([
            'customer_id' => $customer->id,
            'type' => $type,
            'amount' => $amount,
            'balance_type' => 'credit',
        ]);

        return $this->backdate($entry, $createdAt);
    }

    private function makeTicket(Customer $customer, string $status, string $priority = 'medium'): Ticket
    {
        $this->ticketSequence++;

        return Ticket::create([
            'ticket_no' => 'SUP-'.$this->ticketSequence,
            'customer_id' => $customer->id,
            'subject' => 'Test ticket',
            'status' => $status,
            'priority' => $priority,
        ]);
    }

    private function makeProduct(): Product
    {
        if ($this->product === null) {
            $this->product = Product::create([
                'name' => 'Shared Hosting',
                'price' => 100.00,
                'billing_cycle' => 'monthly',
                'status' => 'active',
            ]);
        }

        return $this->product;
    }

    /**
     * Move a row's created_at, which no model exposes as a fillable attribute.
     */
    private function backdate(Model $model, Carbon $timestamp): Model
    {
        $model->forceFill(['created_at' => $timestamp])->save();

        return $model;
    }
}
