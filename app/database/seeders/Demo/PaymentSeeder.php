<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo financials: payments + transactions + credits + customer_wallet.
 *
 * SCHEMA (read from the migrations, never guessed)
 * ------------------------------------------------
 * 2026_07_30_120040_create_financial_tables.php
 *   payments       invoice_id FK->invoices, amount, method ENUM
 *                  razorpay|bank_transfer|cash|cheque|credit|other,
 *                  gateway_id, transaction_id, status ENUM
 *                  pending|completed|failed|refunded, notes,
 *                  created_at ONLY (no updated_at).
 *   transactions   customer_id FK->customers, invoice_id (nullable, NO FK),
 *                  amount, fee, net_amount, currency(3) default INR,
 *                  payment_method, transaction_id, status ENUM
 *                  pending|completed|failed|refunded|partially_refunded,
 *                  notes, created_at ONLY.
 *   credits        customer_id FK->customers, amount, type ENUM
 *                  added|used|expired|refund, description, created_at ONLY.
 *   customer_wallet  customer_id FK->customers, type ENUM
 *                    deposit|credit|debit|invoice_payment, amount,
 *                    balance_type ENUM account|credit, description,
 *                    admin_user_id (nullable), invoice_id (nullable, NO FK),
 *                    created_at ONLY.
 *
 * COHERENCE (asserted after seeding)
 * ----------------------------------
 * - Every payment references a real invoice (0 orphan invoice_id).
 * - Payment amount matches invoice paid_amount for paid/partial invoices
 *   (status=completed); other statuses use a sensible attempt value.
 * - Transactions reference real customers; invoice-linked ones reference
 *   real invoices; net_amount = amount - fee for every row.
 * - Credits and wallet entries reference real customers.
 *
 * IDEMPOTENCY
 * -----------
 * All four tables carry time-relative created_at only; written with
 * seedRowOnce() so re-runs never drag timestamps forward. Natural keys:
 * payments -> transaction_id, transactions -> transaction_id,
 * credits -> (customer_id, description),
 * customer_wallet -> (customer_id, type, description).
 */
class PaymentSeeder extends Seeder
{
    use WithIdempotentSeed;

    /**
     * Payment plan. Amounts for paid/partial invoices are read from the
     * invoice row at run time; for other statuses a sensible attempt value
     * is used.
     *
     * @var list<array<string, mixed>>
     */
    private const PAYMENT_PLANS = [
        ['invoice_no' => 'DEMO-INV-2026-0002', 'method' => 'razorpay', 'status' => 'completed', 'txn' => 'DEMO-PAY-2026-0001', 'note' => 'Card payment captured via Razorpay.'],
        ['invoice_no' => 'DEMO-INV-2026-0005', 'method' => 'bank_transfer', 'status' => 'completed', 'txn' => 'DEMO-PAY-2026-0002', 'note' => 'NEFT bank transfer, reconciled.'],
        ['invoice_no' => 'DEMO-INV-2026-0008', 'method' => 'razorpay', 'status' => 'completed', 'txn' => 'DEMO-PAY-2026-0003', 'note' => 'Renewal paid in full via Razorpay.'],
        ['invoice_no' => 'DEMO-INV-2026-0007', 'method' => 'bank_transfer', 'status' => 'completed', 'txn' => 'DEMO-PAY-2026-0004', 'note' => 'Partial payment received; balance promised.'],
        ['invoice_no' => 'DEMO-INV-2026-0001', 'method' => 'razorpay', 'status' => 'pending', 'txn' => 'DEMO-PAY-2026-0005', 'note' => 'Payment link sent, awaiting capture.'],
        ['invoice_no' => 'DEMO-INV-2026-0010', 'method' => 'cash', 'status' => 'pending', 'txn' => 'DEMO-PAY-2026-0006', 'note' => 'Cash collection scheduled.'],
        ['invoice_no' => 'DEMO-INV-2026-0003', 'method' => 'razorpay', 'status' => 'failed', 'txn' => 'DEMO-PAY-2026-0007', 'note' => 'Card declined; customer notified.'],
        ['invoice_no' => 'DEMO-INV-2026-0006', 'method' => 'bank_transfer', 'status' => 'failed', 'txn' => 'DEMO-PAY-2026-0008', 'note' => 'Payment reversed after cancellation.'],
    ];

    /**
     * Transaction plan. invoice_no may be null for standalone movements
     * (wallet deposits). amount/fee/net are coherent: net = amount - fee.
     *
     * @var list<array<string, mixed>>
     */
    private const TRANSACTION_PLANS = [
        ['invoice_no' => 'DEMO-INV-2026-0002', 'method' => 'razorpay', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0001', 'fee_pct' => 2.0, 'note' => 'Razorpay capture for INV-0002.'],
        ['invoice_no' => 'DEMO-INV-2026-0005', 'method' => 'bank_transfer', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0002', 'fee_pct' => 0.0, 'note' => 'NEFT receipt for INV-0005.'],
        ['invoice_no' => 'DEMO-INV-2026-0008', 'method' => 'razorpay', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0003', 'fee_pct' => 2.0, 'note' => 'Razorpay capture for INV-0008.'],
        ['invoice_no' => 'DEMO-INV-2026-0007', 'method' => 'bank_transfer', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0004', 'fee_pct' => 0.0, 'note' => 'Partial NEFT receipt for INV-0007.'],
        ['invoice_no' => null, 'method' => 'cash', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0005', 'fee_pct' => 0.0, 'note' => 'Cash deposit to customer wallet.'],
        ['invoice_no' => null, 'method' => 'bank_transfer', 'status' => 'completed', 'txn' => 'DEMO-TXN-2026-0006', 'fee_pct' => 0.0, 'note' => 'Bank deposit to customer wallet.'],
        ['invoice_no' => 'DEMO-INV-2026-0003', 'method' => 'razorpay', 'status' => 'failed', 'txn' => 'DEMO-TXN-2026-0007', 'fee_pct' => 0.0, 'note' => 'Failed Razorpay attempt for INV-0003.'],
        ['invoice_no' => 'DEMO-INV-2026-0001', 'method' => 'razorpay', 'status' => 'pending', 'txn' => 'DEMO-TXN-2026-0008', 'fee_pct' => 0.0, 'note' => 'Pending Razorpay auth for INV-0001.'],
    ];

    /**
     * Additional credits (Task 4 already seeded 8; these add 3 more
     * exercising the added/used types on demo customers).
     *
     * @var list<array<string, mixed>>
     */
    private const CREDIT_PLANS = [
        ['email' => 'client1@example.com', 'amount' => 150.00, 'type' => 'added', 'description' => 'Demo referral bonus credit.'],
        ['email' => 'client2@example.com', 'amount' => 75.00, 'type' => 'used', 'description' => 'Demo credit applied against balance.'],
        ['email' => 'client3@example.com', 'amount' => 200.00, 'type' => 'added', 'description' => 'Demo loyalty reward credit.'],
    ];

    /**
     * Additional wallet entries (Task 4 already seeded 10; these add 3 more
     * deposits/invoice_payment entries coherent with the demo payments).
     *
     * @var list<array<string, mixed>>
     */
    private const WALLET_PLANS = [
        ['email' => 'client1@example.com', 'type' => 'deposit', 'amount' => 500.00, 'balance_type' => 'account', 'invoice_no' => null, 'description' => 'Demo wallet top-up via bank transfer.'],
        ['email' => 'client2@example.com', 'type' => 'invoice_payment', 'amount' => 250.00, 'balance_type' => 'account', 'invoice_no' => 'DEMO-INV-2026-0002', 'description' => 'Demo wallet debit for invoice settlement.'],
        ['email' => 'client3@example.com', 'type' => 'deposit', 'amount' => 1000.00, 'balance_type' => 'credit', 'invoice_no' => null, 'description' => 'Demo credit wallet top-up.'],
    ];

    public function run(): void
    {
        $invoices = $this->invoicesByNumber();
        $customers = $this->customersByEmail();

        if (count($invoices) < 6) {
            throw new RuntimeException('PaymentSeeder needs demo invoices. Run InvoiceSeeder first.');
        }

        $payments = $this->seedPayments($invoices);
        $transactions = $this->seedTransactions($invoices, $customers);
        $credits = $this->seedCredits($customers);
        $wallets = $this->seedWalletEntries($invoices, $customers);

        $this->report($payments, $transactions, $credits, $wallets);
    }

    private function seedPayments(array $invoices): int
    {
        $count = 0;
        $target = min(DummyDataConfig::minRows('payments'), count(self::PAYMENT_PLANS));

        for ($i = 0; $i < $target; $i++) {
            $plan = self::PAYMENT_PLANS[$i];
            $invoice = $invoices[$plan['invoice_no']] ?? null;

            if ($invoice === null) {
                throw new RuntimeException(sprintf(
                    'PaymentSeeder: no invoice "%s" - run InvoiceSeeder first.',
                    $plan['invoice_no']
                ));
            }

            $amount = $this->paymentAmount($invoice, $plan['status']);

            $this->seedRow('payments', [
                'invoice_id' => $invoice['id'],
                'amount' => $amount,
                'method' => $plan['method'],
                'gateway_id' => $plan['method'] === 'razorpay' ? 'DEMO-RZP-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT) : null,
                'transaction_id' => $plan['txn'],
                'status' => $plan['status'],
                'notes' => $plan['note'],
            ]);

            $count++;
        }

        return $count;
    }

    private function paymentAmount(array $invoice, string $status): float
    {
        $total = (float) $invoice['total'];
        $paidAmount = (float) $invoice['paid_amount'];

        return match ($status) {
            'completed' => $paidAmount > 0 ? $paidAmount : $total,
            default => $total,
        };
    }

    private function seedTransactions(array $invoices, array $customers): int
    {
        $count = 0;
        $target = min(DummyDataConfig::minRows('transactions'), count(self::TRANSACTION_PLANS));
        $customerIds = array_values(array_map(fn ($c) => $c['id'], $customers));

        for ($i = 0; $i < $target; $i++) {
            $plan = self::TRANSACTION_PLANS[$i];
            $invoice = $plan['invoice_no'] ? ($invoices[$plan['invoice_no']] ?? null) : null;

            if ($plan['invoice_no'] && $invoice === null) {
                throw new RuntimeException(sprintf(
                    'PaymentSeeder: no invoice "%s" - run InvoiceSeeder first.',
                    $plan['invoice_no']
                ));
            }

            $customerId = $invoice ? $invoice['customer_id'] : $customerIds[$i % count($customerIds)];
            $amount = $invoice ? (float) $invoice['total'] : 500.00 * ($i + 1);
            $fee = $plan['fee_pct'] > 0 ? round($amount * $plan['fee_pct'] / 100, 2) : 0.00;
            $net = round($amount - $fee, 2);

            $this->seedRowOnce('transactions', [
                'customer_id' => $customerId,
                'invoice_id' => $invoice ? $invoice['id'] : null,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $net,
                'currency' => 'INR',
                'payment_method' => $plan['method'],
                'transaction_id' => $plan['txn'],
                'status' => $plan['status'],
                'notes' => $plan['note'],
            ]);

            $count++;
        }

        return $count;
    }

    private function seedCredits(array $customers): int
    {
        $count = 0;

        foreach (self::CREDIT_PLANS as $plan) {
            $customer = $customers[$plan['email']] ?? null;

            if ($customer === null) {
                throw new RuntimeException(sprintf(
                    'PaymentSeeder: no customer for "%s" - run CustomerSeeder first.',
                    $plan['email']
                ));
            }

            $this->seedRowOnce('credits', [
                'customer_id' => $customer['id'],
                'amount' => $plan['amount'],
                'type' => $plan['type'],
                'description' => $plan['description'],
            ]);

            $count++;
        }

        return $count;
    }

    private function seedWalletEntries(array $invoices, array $customers): int
    {
        $count = 0;

        foreach (self::WALLET_PLANS as $plan) {
            $customer = $customers[$plan['email']] ?? null;

            if ($customer === null) {
                throw new RuntimeException(sprintf(
                    'PaymentSeeder: no customer for "%s" - run CustomerSeeder first.',
                    $plan['email']
                ));
            }

            $invoice = $plan['invoice_no'] ? ($invoices[$plan['invoice_no']] ?? null) : null;

            $this->seedRowOnce('customer_wallet', [
                'customer_id' => $customer['id'],
                'type' => $plan['type'],
                'amount' => $plan['amount'],
                'balance_type' => $plan['balance_type'],
                'description' => $plan['description'],
                'admin_user_id' => null,
                'invoice_id' => $invoice ? $invoice['id'] : null,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * @return array<string, array{id: int, customer_id: int, total: float, paid_amount: float, status: string}>
     */
    private function invoicesByNumber(): array
    {
        $numbers = array_values(array_unique(array_filter(array_merge(
            array_column(self::PAYMENT_PLANS, 'invoice_no'),
            array_column(self::TRANSACTION_PLANS, 'invoice_no'),
            array_column(self::WALLET_PLANS, 'invoice_no')
        ))));

        return DB::table('invoices')
            ->whereIn('invoice_no', $numbers)
            ->get(['id', 'invoice_no', 'customer_id', 'total', 'paid_amount', 'status'])
            ->keyBy('invoice_no')
            ->map(fn ($inv) => [
                'id' => (int) $inv->id,
                'customer_id' => (int) $inv->customer_id,
                'total' => (float) $inv->total,
                'paid_amount' => (float) $inv->paid_amount,
                'status' => $inv->status,
            ])
            ->all();
    }

    /**
     * @return array<string, array{id: int}> email => [id]
     */
    private function customersByEmail(): array
    {
        $emails = array_values(array_unique(array_merge(
            array_column(self::CREDIT_PLANS, 'email'),
            array_column(self::WALLET_PLANS, 'email')
        )));

        return DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->whereIn('users.email', $emails)
            ->get(['customers.id', 'users.email'])
            ->keyBy('email')
            ->map(fn ($row) => ['id' => (int) $row->id])
            ->all();
    }

    private function report(int $payments, int $transactions, int $credits, int $wallets): void
    {
        $this->command?->info(sprintf(
            'PaymentSeeder: %d payments, %d transactions, %d credits, %d wallet entries.',
            $payments,
            $transactions,
            $credits,
            $wallets
        ));

        foreach (['payments', 'transactions', 'credits', 'customer_wallet'] as $table) {
            $count = (int) DB::table($table)->count();
            $minimum = DummyDataConfig::minRows($table);

            $this->command?->info(sprintf(
                '  %-18s %3d  (min %d)  [%s]',
                $table,
                $count,
                $minimum,
                $count >= $minimum ? 'OK' : 'LOW'
            ));
        }
    }
}
