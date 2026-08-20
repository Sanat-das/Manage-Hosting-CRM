<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Demo billing documents: invoices + invoice_items, plus a safety-net backfill
 * of quote_items for any quote that has none.
 *
 * SCHEMA (read from the migrations, never guessed)
 * ------------------------------------------------
 * 2026_07_30_120040_create_financial_tables.php
 *   invoices       invoice_no UNIQUE, customer_id FK->customers,
 *                  order_id (nullable, NO FK), amount/tax/discount/total,
 *                  tax_rate decimal(5,2), gst_enabled bool,
 *                  cgst_/sgst_/igst_ rate+amount (all nullable),
 *                  due_date DATE NOT NULL, paid_at, notes, timestamps
 *   invoice_items  invoice_id FK->invoices, product_id (nullable, NO FK),
 *                  description, quantity, unit_price, total,
 *                  gst_enabled, gst_rate, gst_type ENUM
 *                  standard|exempt|reverse_charge, cgst_/sgst_/igst_ pairs.
 *                  NO timestamp columns at all - the trait detects that.
 * 2026_07_31_000001_add_billing_columns.php
 *   invoices.status was WIDENED to the 7-value superset
 *   draft|sent|paid|overdue|partial|void|cancelled and gained
 *   paid_amount decimal(14,2), last_reminder_at, reminder_count.
 *   Reading only the create migration would make 'partial' look invalid.
 *
 * GST COMES FROM `gst_settings`, NOT FROM CONSTANTS
 * -------------------------------------------------
 * The home state code and the cgst/sgst/igst rates are read from the single
 * `gst_settings` row (2026_07_30_120020_create_config_tables.php) at run time.
 * A customer whose `customers.state_code` equals the home state is billed
 * INTRA-state (cgst + sgst); anyone else is billed INTER-state (igst). A NULL
 * customer state falls back to intra, matching the app's own default.
 * `gst_settings.enabled` is the live-billing toggle for the application; the
 * demo set deliberately exercises BOTH branches (one invoice is issued with
 * gst_enabled = false) while always sourcing its rates from that table.
 *
 * ARITHMETIC INVARIANTS (asserted after seeding, see evidence file)
 * -----------------------------------------------------------------
 *   item.total        = unit_price * quantity
 *   item tax          = cgst_amount + sgst_amount  (intra)  |  igst_amount (inter)
 *   invoice.amount    = SUM(item.total)
 *   invoice.tax       = cgst_amount + sgst_amount  (intra)  |  igst_amount (inter)
 *   invoice.total     = amount + tax - discount
 *   0 <= paid_amount <= total, and paid_amount = total exactly when status=paid
 *
 * FOREIGN KEYS ARE RESOLVED LAZILY AT RUN TIME
 * --------------------------------------------
 * Orders are looked up by `order_number`, customers through the order (falling
 * back to the demo login e-mail) and products by `name`. `order_id` and
 * `product_id` have no database FK, so an unresolved lookup would rot silently
 * instead of failing - every lookup therefore throws rather than writing NULL.
 *
 * IDEMPOTENCY
 * -----------
 * Invoices carry time-relative payloads (due_date, paid_at, last_reminder_at)
 * and are written with `seedRowOnce()` so a re-run never drags them forward.
 * Line items are fully derived from the templates and are written with
 * `seedRow()` so they converge on the plan even if an earlier run wrote them
 * differently. Natural keys: invoices -> invoice_no, invoice_items ->
 * (invoice_id, description), quote_items -> (quote_id, description).
 */
class InvoiceSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Deterministic invoice_no scheme; invoice_no is a real UNIQUE index. */
    private const NUMBER_FORMAT = 'DEMO-INV-2026-%04d';

    /** Fallback GST rates used only when `gst_settings` is empty. */
    private const FALLBACK_GST = ['state_code' => '27', 'cgst' => 9.00, 'sgst' => 9.00, 'igst' => 18.00];

    /**
     * Service lines that are not catalog products (product_id stays NULL).
     *
     * @var array<string, float>
     */
    private const CUSTOM_LINES = [
        'Demo Setup & Onboarding Fee' => 999.00,
        'Demo Priority Support Retainer' => 1999.00,
    ];

    /**
     * The demo invoice book. `order_number` resolves to a real order (and to
     * that order's customer) at run time; each item names either a catalog
     * product or one of CUSTOM_LINES.
     *
     * due_days is relative to today: negative = already elapsed.
     *
     * @var list<array<string, mixed>>
     */
    private const INVOICES = [
        [
            'order_number' => 'ORD-2026-0101',
            'status' => 'sent',
            'discount' => 0.00,
            'due_days' => 12,
            'gst_enabled' => true,
            'notes' => 'Initial invoice for the onboarding order; awaiting payment.',
            'items' => [
                ['product' => 'Demo Starter Shared Hosting', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo .com Domain Registration', 'quantity' => 1, 'gst_type' => 'exempt'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0102',
            'status' => 'paid',
            'discount' => 50.00,
            'due_days' => -18,
            'gst_enabled' => true,
            'notes' => 'Settled by card on the day of issue; loyalty discount applied.',
            'items' => [
                ['product' => 'Demo Business Shared Hosting', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo SSL & Backup Addon', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0103',
            'status' => 'overdue',
            'discount' => 0.00,
            'due_days' => -21,
            'gst_enabled' => true,
            'notes' => 'Past due; two dunning reminders sent, suspension pending.',
            'items' => [
                ['product' => 'Demo Cloud VPS 2GB', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo SSL & Backup Addon', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0104',
            'status' => 'overdue',
            'discount' => 25.00,
            'due_days' => -34,
            'gst_enabled' => true,
            'notes' => 'Service already suspended for non-payment; escalated to accounts.',
            'items' => [
                ['product' => 'Demo Legacy Hosting Pack', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo .com Domain Registration', 'quantity' => 1, 'gst_type' => 'exempt'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0105',
            'status' => 'paid',
            'discount' => 0.00,
            'due_days' => -9,
            'gst_enabled' => true,
            'notes' => 'Annual domain registration paid in full via bank transfer.',
            'items' => [
                ['product' => 'Demo .com Domain Registration', 'quantity' => 1, 'gst_type' => 'exempt'],
                ['product' => 'Demo Setup & Onboarding Fee', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0106',
            'status' => 'cancelled',
            'discount' => 0.00,
            'due_days' => 6,
            'gst_enabled' => false,
            'notes' => 'Order cancelled inside the cooling-off window; invoice voided, no GST charged.',
            'items' => [
                ['product' => 'Demo SSL & Backup Addon', 'quantity' => 1, 'gst_type' => 'exempt'],
                ['product' => 'Demo Setup & Onboarding Fee', 'quantity' => 1, 'gst_type' => 'exempt'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0107',
            'status' => 'partial',
            'discount' => 100.00,
            'due_days' => -4,
            'gst_enabled' => true,
            'notes' => 'Customer paid a part amount; balance promised for next week.',
            'items' => [
                ['product' => 'Demo Cloud VPS 8GB', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo SSL & Backup Addon', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0108',
            'status' => 'paid',
            'discount' => 150.00,
            'due_days' => -27,
            'gst_enabled' => true,
            'notes' => 'Reseller package renewal, paid on receipt.',
            'items' => [
                ['product' => 'Demo Reseller Bronze', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo Business Shared Hosting', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0109',
            'status' => 'draft',
            'discount' => 500.00,
            'due_days' => 30,
            'gst_enabled' => true,
            'notes' => 'Draft pro-forma for the hardware refresh; not yet issued to the customer.',
            'items' => [
                ['product' => 'Demo Dedicated E3 Server', 'quantity' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo .com Domain Registration', 'quantity' => 2, 'gst_type' => 'exempt'],
            ],
        ],
        [
            'order_number' => 'ORD-2026-0110',
            'status' => 'sent',
            'discount' => 0.00,
            'due_days' => 20,
            'gst_enabled' => true,
            'notes' => 'Semi-annual billing run; retainer billed alongside the hosting plan.',
            'items' => [
                ['product' => 'Demo Business Shared Hosting', 'quantity' => 2, 'gst_type' => 'standard'],
                ['product' => 'Demo Priority Support Retainer', 'quantity' => 1, 'gst_type' => 'standard'],
            ],
        ],
    ];

    public function run(): void
    {
        $gst = $this->gstSettings();
        $orders = $this->ordersByNumber();
        $prices = $this->productsByName();

        if ($orders === []) {
            throw new RuntimeException('InvoiceSeeder needs demo orders. Run OrderSeeder first.');
        }

        $target = min(DummyDataConfig::minRows('invoices'), count(self::INVOICES));
        $seeded = 0;
        $items = 0;

        for ($index = 0; $index < $target; $index++) {
            $template = self::INVOICES[$index];
            $order = $orders[$template['order_number']] ?? null;

            if ($order === null) {
                throw new RuntimeException(sprintf(
                    'InvoiceSeeder: no order "%s" - run OrderSeeder first.',
                    $template['order_number']
                ));
            }

            $plan = $this->buildPlan($index, $template, $order, $prices, $gst);
            $invoiceId = (int) $this->seedRowOnce('invoices', $plan['invoice']);

            foreach ($plan['items'] as $item) {
                $this->seedRow('invoice_items', ['invoice_id' => $invoiceId] + $item);
                $items++;
            }

            $seeded++;
        }

        $backfilled = $this->backfillQuoteItems($prices, $gst);

        $this->seedPdfLog();

        $this->report($seeded, $items, $backfilled);
    }

    /**
     * One PDF-generation log entry per issued invoice.
     *
     * Schema (2026_08_13_000002_create_invoice_pdf_log_table.php): invoice_id
     * and customer_id are nullable FKs to invoices/customers, generated_by is a
     * plain unsignedBigInteger with NO FK, file_name NOT NULL, file_size and
     * file_path nullable, plus timestamps.
     *
     * In production the app writes this row from the admin PDF download route
     * (see AdminInvoiceController / PortedTablesTest). The demo set needs the
     * table populated so the "PDF history" panel is not empty, so one row per
     * NON-DRAFT invoice is recorded - a draft has never been issued and must
     * not appear to have had a PDF generated.
     *
     * Natural key is (invoice_id, file_name); file_name mirrors the real
     * route's `invoice-{invoice_no}.pdf` convention, so a subsequent genuine
     * download updates this row instead of duplicating it.
     *
     * `generated_by` resolves to the protected admin login by e-mail, never a
     * literal id. file_size is derived from the invoice number so it is stable
     * across runs, and file_path is '' exactly as the live route writes it
     * (the PDF is streamed, never persisted to disk).
     */
    private function seedPdfLog(): int
    {
        if (! Schema::hasTable('invoice_pdf_log')) {
            return 0;
        }

        $generatedBy = $this->adminUserId();

        $invoices = DB::table('invoices')
            ->where('invoice_no', 'like', 'DEMO-INV-%')
            ->where('status', '!=', 'draft')
            ->orderBy('id')
            ->get(['id', 'invoice_no', 'customer_id']);

        $seeded = 0;

        foreach ($invoices as $invoice) {
            $fileName = "invoice-{$invoice->invoice_no}.pdf";

            $this->seedRow('invoice_pdf_log', [
                'invoice_id' => (int) $invoice->id,
                'customer_id' => (int) $invoice->customer_id,
                'generated_by' => $generatedBy,
                'file_name' => $fileName,
                'file_size' => 24_000 + (strlen($fileName) * 137),
                'file_path' => '',
                'mime_title' => 'application/pdf',
            ]);

            $seeded++;
        }

        return $seeded;
    }

    /**
     * The protected admin login's id, resolved by e-mail.
     *
     * Auto-increment ids differ between the dev database and a fresh
     * `migrate:fresh`, so the id is never assumed - only the e-mail declared in
     * DummyDataConfig::PROTECTED_ROWS is a contract. Returns null (a nullable
     * column) rather than throwing when no admin exists, since the PDF log is
     * evidence of an action, not a required relationship.
     */
    private function adminUserId(): ?int
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        if ($protected === []) {
            return null;
        }

        $id = DB::table('users')->where($protected)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Expand one template into a persistable invoice row plus its line items,
     * computing every money column from the live product prices and the live
     * GST settings so the arithmetic is correct by construction.
     *
     * @param  array<string, mixed>  $template
     * @param  array{id: int, customer_id: int}  $order
     * @param  array<string, array{id: ?int, price: float}>  $prices
     * @param  array<string, mixed>  $gst
     * @return array{invoice: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function buildPlan(int $index, array $template, array $order, array $prices, array $gst): array
    {
        $intra = $this->isIntraState($order['customer_id'], $gst);
        $invoiceGst = (bool) $template['gst_enabled'];

        $items = [];
        $amount = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;

        foreach ($template['items'] as $line) {
            $product = $prices[$line['product']] ?? null;

            if ($product === null) {
                throw new RuntimeException(sprintf(
                    'InvoiceSeeder: unknown product "%s" - run ProductSeeder first.',
                    $line['product']
                ));
            }

            $quantity = (int) $line['quantity'];
            $unitPrice = $product['price'];
            $total = round($unitPrice * $quantity, 2);
            $taxable = $invoiceGst && $line['gst_type'] === 'standard';

            $row = [
                'product_id' => $product['id'],
                'description' => $line['product'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
                'gst_enabled' => $taxable,
                'gst_rate' => $taxable ? $this->totalRate($intra, $gst) : 0.00,
                'gst_type' => $line['gst_type'],
                'cgst_rate' => null,
                'cgst_amount' => null,
                'sgst_rate' => null,
                'sgst_amount' => null,
                'igst_rate' => null,
                'igst_amount' => null,
            ];

            if ($taxable && $intra) {
                $row['cgst_rate'] = $gst['cgst'];
                $row['cgst_amount'] = round($total * $gst['cgst'] / 100, 2);
                $row['sgst_rate'] = $gst['sgst'];
                $row['sgst_amount'] = round($total * $gst['sgst'] / 100, 2);
                $cgst += $row['cgst_amount'];
                $sgst += $row['sgst_amount'];
            } elseif ($taxable) {
                $row['igst_rate'] = $gst['igst'];
                $row['igst_amount'] = round($total * $gst['igst'] / 100, 2);
                $igst += $row['igst_amount'];
            }

            $amount += $total;
            $items[] = $row;
        }

        $amount = round($amount, 2);
        $cgst = round($cgst, 2);
        $sgst = round($sgst, 2);
        $igst = round($igst, 2);
        $tax = round($cgst + $sgst + $igst, 2);
        $discount = min((float) $template['discount'], $amount);
        $total = round($amount + $tax - $discount, 2);

        $status = $template['status'];
        $dueDate = now()->startOfDay()->addDays((int) $template['due_days']);

        return [
            'invoice' => [
                'invoice_no' => sprintf(self::NUMBER_FORMAT, $index + 1),
                'customer_id' => $order['customer_id'],
                'order_id' => $order['id'],
                'amount' => $amount,
                'tax' => $tax,
                'tax_rate' => $tax > 0 ? $this->totalRate($intra, $gst) : 0.00,
                'discount' => $discount,
                'total' => $total,
                'paid_amount' => $this->paidAmount($status, $total),
                'gst_enabled' => $tax > 0,
                'cgst_rate' => $cgst > 0 ? $gst['cgst'] : null,
                'cgst_amount' => $cgst > 0 ? $cgst : null,
                'sgst_rate' => $sgst > 0 ? $gst['sgst'] : null,
                'sgst_amount' => $sgst > 0 ? $sgst : null,
                'igst_rate' => $igst > 0 ? $gst['igst'] : null,
                'igst_amount' => $igst > 0 ? $igst : null,
                'status' => $status,
                'due_date' => $dueDate->toDateString(),
                'paid_at' => $status === 'paid' ? $dueDate->copy()->subDay()->setTime(11, 30)->toDateTimeString() : null,
                'last_reminder_at' => $status === 'overdue' ? now()->startOfDay()->subDays(3)->setTime(9, 0)->toDateTimeString() : null,
                'reminder_count' => $status === 'overdue' ? 2 : 0,
                'notes' => $template['notes'],
            ],
            'items' => $items,
        ];
    }

    /**
     * Money actually received, kept coherent with the status:
     * paid = settled in full, partial = 40% rounded to the rupee, everything
     * else (draft/sent/overdue/cancelled) = nothing received yet.
     */
    private function paidAmount(string $status, float $total): float
    {
        return match ($status) {
            'paid' => $total,
            'partial' => min($total, round($total * 0.4, 2)),
            default => 0.00,
        };
    }

    /** Combined GST percentage for the applicable treatment. */
    private function totalRate(bool $intra, array $gst): float
    {
        return round($intra ? $gst['cgst'] + $gst['sgst'] : $gst['igst'], 2);
    }

    /**
     * Intra-state when the customer sits in the seller's own state. A customer
     * without a recorded state_code defaults to intra-state, mirroring the
     * application's own fallback.
     *
     * @param  array<string, mixed>  $gst
     */
    private function isIntraState(int $customerId, array $gst): bool
    {
        $state = DB::table('customers')->where('id', $customerId)->value('state_code');

        return $state === null || $state === '' || (string) $state === (string) $gst['state_code'];
    }

    /**
     * Safety net for quotes that carry no line items (QuoteSeeder owns the
     * normal case). Each empty quote is given TWO lines whose `total_price`
     * sums back to the quote's stored `subtotal` and whose `tax_amount` sums
     * back to its stored `tax`, so the quote's own arithmetic stays intact —
     * this seeder never rewrites a `quotes` row. Two lines per quote also
     * keeps the table at its `ROWS['quote_items']` minimum when the demo
     * quote set is the only source.
     *
     * @param  array<string, array{id: ?int, price: float}>  $prices
     * @param  array<string, mixed>  $gst
     * @return int number of quotes that were backfilled
     */
    private function backfillQuoteItems(array $prices, array $gst): int
    {
        $empty = DB::table('quotes')
            ->leftJoin('quote_items', 'quote_items.quote_id', '=', 'quotes.id')
            ->whereNull('quote_items.id')
            ->get(['quotes.id', 'quotes.subject', 'quotes.subtotal', 'quotes.tax']);

        if ($empty->isEmpty()) {
            return 0;
        }

        $fallbackPrice = $prices !== [] ? reset($prices)['price'] : 999.00;

        foreach ($empty as $quote) {
            $subtotal = (float) $quote->subtotal > 0 ? round((float) $quote->subtotal, 2) : $fallbackPrice;
            $tax = round((float) $quote->tax, 2);

            // Split 60/40 so both lines carry a whole-paisa share and the
            // remainder lands on the second line rather than being lost.
            $firstTotal = round($subtotal * 0.6, 2);
            $firstTax = round($tax * 0.6, 2);

            $split = [
                ['label' => 'Demo quoted service - '.$quote->subject, 'total' => $firstTotal, 'tax' => $firstTax],
                ['label' => 'Demo quoted support - '.$quote->subject, 'total' => round($subtotal - $firstTotal, 2), 'tax' => round($tax - $firstTax, 2)],
            ];

            foreach ($split as $line) {
                $standard = $line['tax'] > 0;

                $this->seedRow('quote_items', [
                    'quote_id' => (int) $quote->id,
                    'description' => $line['label'],
                    'qty' => 1,
                    'unit_price' => $line['total'],
                    'total_price' => $line['total'],
                    'gst_type' => $standard ? 'standard' : 'exempt',
                    'gst_rate' => $standard ? round($line['tax'] / max($line['total'], 0.01) * 100, 2) : 0.00,
                    'tax_amount' => $line['tax'],
                ]);
            }
        }

        return $empty->count();
    }

    /**
     * Seller GST profile, read from the single `gst_settings` row.
     *
     * @return array{state_code: string, cgst: float, sgst: float, igst: float}
     */
    private function gstSettings(): array
    {
        $row = DB::table('gst_settings')->orderBy('id')->first();

        if ($row === null) {
            return self::FALLBACK_GST;
        }

        return [
            'state_code' => (string) ($row->state_code ?? self::FALLBACK_GST['state_code']),
            'cgst' => (float) ($row->cgst_rate ?? self::FALLBACK_GST['cgst']),
            'sgst' => (float) ($row->sgst_rate ?? self::FALLBACK_GST['sgst']),
            'igst' => (float) ($row->igst_rate ?? self::FALLBACK_GST['igst']),
        ];
    }

    /**
     * @return array<string, array{id: int, customer_id: int}> order_number => [id, customer_id]
     */
    private function ordersByNumber(): array
    {
        $numbers = array_values(array_unique(array_column(self::INVOICES, 'order_number')));

        return DB::table('orders')
            ->whereIn('order_number', $numbers)
            ->get(['id', 'order_number', 'customer_id'])
            ->keyBy('order_number')
            ->map(fn ($order) => ['id' => (int) $order->id, 'customer_id' => (int) $order->customer_id])
            ->all();
    }

    /**
     * Catalog products (real product_id) plus the non-catalog service lines
     * (product_id NULL), keyed by the description written on the invoice.
     *
     * @return array<string, array{id: ?int, price: float}>
     */
    private function productsByName(): array
    {
        $catalog = DB::table('products')
            ->orderBy('id')
            ->get(['id', 'name', 'price'])
            ->keyBy('name')
            ->map(fn ($product) => ['id' => (int) $product->id, 'price' => (float) $product->price])
            ->all();

        foreach (self::CUSTOM_LINES as $name => $price) {
            $catalog[$name] ??= ['id' => null, 'price' => $price];
        }

        return $catalog;
    }

    private function report(int $invoices, int $items, int $backfilled): void
    {
        $this->command?->info(sprintf(
            'InvoiceSeeder: %d invoices planned, %d line items planned, %d empty quotes backfilled.',
            $invoices,
            $items,
            $backfilled
        ));

        foreach (['invoices', 'invoice_items', 'quote_items', 'invoice_pdf_log'] as $table) {
            $count = (int) DB::table($table)->count();
            $minimum = DummyDataConfig::minRows($table);

            $this->command?->info(sprintf(
                '  %-15s %3d  (min %d)  [%s]',
                $table,
                $count,
                $minimum,
                $count >= $minimum ? 'OK' : 'LOW'
            ));
        }
    }
}
