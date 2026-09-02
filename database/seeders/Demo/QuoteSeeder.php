<?php

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seed demo sales quotes and their line items.
 *
 * SCHEMA (read from the migrations, never guessed)
 * ------------------------------------------------
 * 2026_07_30_120040_create_financial_tables.php (quotes):
 *   customer_id  FK -> customers, cascadeOnDelete
 *   quote_no, subject, notes, valid_until (date), timestamps
 *   stage ENUM draft|delivered|accepted|rejected|dead   (default draft)
 *   subtotal / discount / tax / total  decimal(12,2)
 *
 * 2026_07_31_000001_add_billing_columns.php (quote_items):
 *   quote_id FK -> quotes, cascadeOnDelete
 *   description, qty (unsignedInteger), unit_price, total_price
 *   gst_type ENUM standard|exempt|reverse_charge (nullable)
 *   gst_rate decimal(5,2) nullable, tax_amount decimal(12,2)
 *   NO timestamp columns at all - the trait handles that automatically.
 *
 * FOREIGN KEYS ARE RESOLVED LAZILY AT RUN TIME
 * --------------------------------------------
 * Customer ids are looked up by the demo client e-mail pattern (falling back
 * to any customer), and product prices by product name, so this seeder never
 * assumes fixed ids and stays correct when it runs in parallel with, before,
 * or after the customer/product seeders.
 *
 * There is no product_id column on quote_items, so a line item references its
 * product through the product NAME in `description` and the product's live
 * `price` as `unit_price` - the same convention the app's quote UI uses.
 */
class QuoteSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** GST rate applied to standard-rated demo lines. */
    private const GST_RATE = 18.00;

    /**
     * Quote templates. Each line item names a product from ProductSeeder;
     * the unit price is taken from the live products row at run time.
     *
     * @var list<array<string, mixed>>
     */
    private const QUOTES = [
        [
            'quote_no' => 'DEMO-QT-2026-0001',
            'subject' => 'Shared hosting bundle for corporate website',
            'stage' => 'accepted',
            'discount' => 200.00,
            'valid_until_days' => 30,
            'notes' => 'Accepted by the customer; convert to order on approval.',
            'items' => [
                ['product' => 'Demo Business Shared Hosting', 'qty' => 2, 'gst_type' => 'standard'],
                ['product' => 'Demo SSL & Backup Addon', 'qty' => 1, 'gst_type' => 'standard'],
            ],
        ],
        [
            'quote_no' => 'DEMO-QT-2026-0002',
            'subject' => 'Cloud VPS migration proposal',
            'stage' => 'delivered',
            'discount' => 0.00,
            'valid_until_days' => 21,
            'notes' => 'Sent to the customer, awaiting a decision.',
            'items' => [
                ['product' => 'Demo Cloud VPS 8GB', 'qty' => 2, 'gst_type' => 'standard'],
                ['product' => 'Demo Cloud VPS 2GB', 'qty' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo SSL & Backup Addon', 'qty' => 2, 'gst_type' => 'standard'],
            ],
        ],
        [
            'quote_no' => 'DEMO-QT-2026-0003',
            'subject' => 'Reseller programme starter package',
            'stage' => 'draft',
            'discount' => 100.00,
            'valid_until_days' => 14,
            'notes' => 'Draft - pricing still under review by sales.',
            'items' => [
                ['product' => 'Demo Reseller Bronze', 'qty' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo Starter Shared Hosting', 'qty' => 3, 'gst_type' => 'standard'],
            ],
        ],
        [
            'quote_no' => 'DEMO-QT-2026-0004',
            'subject' => 'Dedicated server refresh with domain renewals',
            'stage' => 'delivered',
            'discount' => 500.00,
            'valid_until_days' => 45,
            'notes' => 'Hardware refresh proposal including a two-year domain renewal.',
            'items' => [
                ['product' => 'Demo Dedicated E3 Server', 'qty' => 1, 'gst_type' => 'standard'],
                ['product' => 'Demo .com Domain Registration', 'qty' => 2, 'gst_type' => 'exempt'],
            ],
        ],
        [
            'quote_no' => 'DEMO-QT-2026-0005',
            'subject' => 'Legacy hosting consolidation',
            'stage' => 'rejected',
            'discount' => 0.00,
            'valid_until_days' => -10,
            'notes' => 'Customer declined; kept for pipeline reporting.',
            'items' => [
                ['product' => 'Demo Legacy Hosting Pack', 'qty' => 4, 'gst_type' => 'standard'],
                ['product' => 'Demo Starter Shared Hosting', 'qty' => 1, 'gst_type' => 'reverse_charge'],
            ],
        ],
    ];

    public function run(): void
    {
        $customerIds = $this->customerIds();

        if ($customerIds === []) {
            throw new RuntimeException('QuoteSeeder needs at least 1 customer. Run CustomerSeeder first.');
        }

        $prices = $this->productPrices();

        if ($prices === []) {
            throw new RuntimeException('QuoteSeeder needs at least 1 product. Run ProductSeeder first.');
        }

        $target = min(DummyDataConfig::minRows('quotes'), count(self::QUOTES));
        $plans = [];

        for ($i = 0; $i < $target; $i++) {
            $plans[$i] = $this->buildPlan($i, $customerIds, $prices);
        }

        $quoteCount = $this->seedUpTo('quotes', fn (int $i): array => $plans[$i]['quote'], $target);
        $itemCount = $this->seedItems($plans);

        $this->command?->info(sprintf(
            'QuoteSeeder: quotes=%d, quote_items=%d',
            $quoteCount,
            $itemCount
        ));
    }

    /**
     * Expand one template into a persistable quote row plus its item rows,
     * pricing every line from the live products table.
     *
     * @param  list<int>  $customerIds
     * @param  array<string, float>  $prices
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function buildPlan(int $index, array $customerIds, array $prices): array
    {
        $template = self::QUOTES[$index];
        $productNames = array_keys($prices);

        $items = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($template['items'] as $position => $line) {
            $name = array_key_exists($line['product'], $prices)
                ? $line['product']
                : $productNames[($index + $position) % count($productNames)];

            $unitPrice = $prices[$name];
            $totalPrice = round($unitPrice * $line['qty'], 2);
            $gstRate = $line['gst_type'] === 'standard' ? self::GST_RATE : 0.00;
            $taxAmount = round($totalPrice * $gstRate / 100, 2);

            $subtotal += $totalPrice;
            $tax += $taxAmount;

            $items[] = [
                'description' => $name,
                'qty' => $line['qty'],
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'gst_type' => $line['gst_type'],
                'gst_rate' => $gstRate,
                'tax_amount' => $taxAmount,
            ];
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);
        $discount = (float) $template['discount'];

        return [
            'quote' => [
                'customer_id' => $customerIds[$index % count($customerIds)],
                'quote_no' => $template['quote_no'],
                'subject' => $template['subject'],
                'stage' => $template['stage'],
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => round($subtotal - $discount + $tax, 2),
                'notes' => $template['notes'],
                'valid_until' => now()->addDays((int) $template['valid_until_days'])->toDateString(),
            ],
            'items' => $items,
        ];
    }

    /**
     * Seed the line items of every planned quote.
     * Natural key is (quote_id, description), so the quote id is resolved from
     * the just-seeded quote row rather than assumed.
     *
     * @param  array<int, array{quote: array<string, mixed>, items: list<array<string, mixed>>}>  $plans
     * @return int total quote_items rows in the table afterwards
     */
    private function seedItems(array $plans): int
    {
        foreach ($plans as $plan) {
            $quoteId = DB::table('quotes')
                ->where('quote_no', $plan['quote']['quote_no'])
                ->value('id');

            if ($quoteId === null) {
                continue;
            }

            foreach ($plan['items'] as $item) {
                $this->seedRow('quote_items', ['quote_id' => (int) $quoteId] + $item);
            }
        }

        return (int) DB::table('quote_items')->count();
    }

    /**
     * Demo customers first (the accounts CustomerSeeder owns), falling back to
     * whatever customers exist. Resolved at run time, never hard-coded.
     *
     * @return list<int>
     */
    private function customerIds(): array
    {
        $demo = DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->where('users.email', 'like', 'client%@example.com')
            ->orderBy('customers.id')
            ->pluck('customers.id')
            ->all();

        if ($demo !== []) {
            return array_map('intval', $demo);
        }

        return array_map('intval', DB::table('customers')->orderBy('id')->pluck('id')->all());
    }

    /**
     * Live product prices keyed by name, resolved at run time.
     *
     * @return array<string, float>
     */
    private function productPrices(): array
    {
        return array_map(
            static fn ($price): float => (float) $price,
            DB::table('products')->orderBy('id')->pluck('price', 'name')->all()
        );
    }
}
