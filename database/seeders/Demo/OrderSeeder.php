<?php

namespace Database\Seeders\Demo;

use App\Models\Order;
use Database\Seeders\Demo\Traits\WithIdempotentSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo sales orders: orders + order_items + order_status_history.
 *
 * Seeds `DummyDataConfig::ROWS['orders']` (10) demo orders spread across the
 * demo customers, each with 1-2 line items referencing real catalog products
 * and a full status-history chain describing how the order reached its current
 * state. Totals meet the configured minima: orders 10, order_items 17,
 * order_status_history 35.
 *
 * FOREIGN KEYS ARE RESOLVED AT RUN TIME, NEVER HARDCODED
 * ------------------------------------------------------
 * Customers are looked up through `users.email` (the demo logins created by
 * CustomerSeeder) and products through `products.name` (the demo catalog
 * created by ProductSeeder). Ids differ between the dev database and a fresh
 * sqlite migration, so nothing may be cached across runs.
 *
 * ENUM VALUES ARE TAKEN FROM THE MIGRATIONS, NOT GUESSED
 * ------------------------------------------------------
 * 2026_07_30_120030_create_order_tables.php +
 * 2026_08_07_000001_order_lifecycle_and_status_history.php declare:
 *   orders.status         pending|active|suspended|cancelled|terminated|
 *                         paid|provisioning|failed
 *   orders.billing_cycle  monthly|quarterly|semi_annual|annual|biennial|
 *                         one_time   (2026_08_01_000001_add_order_number...)
 *   order_status_history.from_status / to_status are plain nullable strings —
 *   no CHECK constraint — but only the eight `orders.status` literals above
 *   are used so the audit trail stays meaningful.
 * SQLite compiles the enums into CHECK constraints, so any other literal would
 * abort the seed.
 *
 * IDEMPOTENCY
 * -----------
 * Orders are written through `WithIdempotentSeed::seedRow()` — updateOrCreate
 * on the `order_number` natural key — so a re-run corrects `total`/`quantity`
 * in place when they changed. They are recomputed from the resolved item
 * prices on every run and are deterministic, so the resulting state is stable.
 * Everything else goes through `seedRowOnce()` — firstOrCreate semantics on
 * the natural keys from `DummyDataConfig::NATURAL_KEYS` (order_items →
 * order_id+product_name, order_status_history → order_id+from_status+to_status)
 * — so re-running adds zero rows and leaves any order an operator created by
 * hand untouched.
 *
 * 0-ORPHAN CLEANUP
 * ----------------
 * Before seeding, `purgeUnresolvableOrders()` deletes any pre-existing order
 * whose `customer_id` or `product_id` no longer resolves to a real row (legacy
 * rows from before the demo project), together with its `order_items` and
 * `order_status_history`, all inside one transaction. Orders whose foreign keys
 * DO resolve are never touched, regardless of status.
 */
class OrderSeeder extends Seeder
{
    use WithIdempotentSeed;

    /** Deterministic order_number prefix; the sequence never changes per run. */
    private const NUMBER_FORMAT = 'ORD-2026-%04d';

    /** First sequence number, chosen to sit clear of hand-made order numbers. */
    private const NUMBER_OFFSET = 100;

    /**
     * The canonical lifecycle. A status history chain for an order is the
     * prefix of one of these paths ending at the order's current status, so
     * every `from_status → to_status` hop is coherent and the last hop always
     * lands on `orders.status`.
     *
     * @var array<string, list<string>>
     */
    private const LIFECYCLE = [
        'pending' => ['pending'],
        'paid' => ['pending', 'paid'],
        'provisioning' => ['pending', 'paid', 'provisioning'],
        'active' => ['pending', 'paid', 'provisioning', 'active'],
        'suspended' => ['pending', 'paid', 'provisioning', 'active', 'suspended'],
        'terminated' => ['pending', 'paid', 'provisioning', 'active', 'suspended', 'terminated'],
        'cancelled' => ['pending', 'cancelled'],
        'failed' => ['pending', 'paid', 'provisioning', 'failed'],
    ];

    /** Human readable reason recorded on each hop of the chain. */
    private const HOP_NOTES = [
        'pending' => 'Order placed from the client area.',
        'paid' => 'Payment captured and reconciled against the invoice.',
        'provisioning' => 'Handed to the provisioning queue.',
        'active' => 'Provisioning completed, welcome email sent.',
        'suspended' => 'Suspended automatically after the dunning cycle expired.',
        'terminated' => 'Terminated after the suspension grace period.',
        'cancelled' => 'Cancelled by the customer before activation.',
        'failed' => 'Provisioning failed, retry queued for an operator.',
    ];

    /**
     * Demo order definitions. `customer_email` and every `product_name`
     * resolve to real ids at run time; totals are recomputed from the
     * looked-up product price so the numbers stay correct even after
     * ProductSeeder re-runs.
     *
     * @var list<array<string, mixed>>
     */
    private const ORDERS = [
        [
            'customer_email' => 'client1@example.com',
            'product_name' => 'Demo Starter Shared Hosting',
            'billing_cycle' => 'monthly',
            'status' => 'pending',
            'domain' => 'northwind.test',
            'notes' => 'New customer onboarding - domain transfer pending.',
            'items' => [
                ['product_name' => 'Demo Starter Shared Hosting', 'quantity' => 1],
                ['product_name' => 'Demo .com Domain Registration', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client2@example.com',
            'product_name' => 'Demo Business Shared Hosting',
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'domain' => 'blueharbor.test',
            'notes' => 'Invoice paid; auto-renew enabled.',
            'items' => [
                ['product_name' => 'Demo Business Shared Hosting', 'quantity' => 1],
                ['product_name' => 'Demo SSL & Backup Addon', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client3@example.com',
            'product_name' => 'Demo Cloud VPS 2GB',
            'billing_cycle' => 'monthly',
            'status' => 'provisioning',
            'domain' => 'cedarpoint.test',
            'notes' => 'OS selected: ubuntu-22.04. Provisioning via Virtualizor.',
            'items' => [
                ['product_name' => 'Demo Cloud VPS 2GB', 'quantity' => 1],
                ['product_name' => 'Demo SSL & Backup Addon', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client4@example.com',
            'product_name' => 'Demo Legacy Hosting Pack',
            'billing_cycle' => 'quarterly',
            'status' => 'suspended',
            'domain' => 'driftwood.test',
            'notes' => 'Payment overdue 14 days - service suspended per policy.',
            'items' => [
                ['product_name' => 'Demo Legacy Hosting Pack', 'quantity' => 1],
                ['product_name' => 'Demo .com Domain Registration', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client5@example.com',
            'product_name' => 'Demo .com Domain Registration',
            'billing_cycle' => 'annual',
            'status' => 'paid',
            'domain' => 'everline.test',
            'notes' => 'Domain registration for the .com TLD, 1 year.',
            'items' => [
                ['product_name' => 'Demo .com Domain Registration', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client1@example.com',
            'product_name' => 'Demo SSL & Backup Addon',
            'billing_cycle' => 'annual',
            'status' => 'cancelled',
            'domain' => null,
            'notes' => 'Cancelled within the cooling-off period, full refund issued.',
            'items' => [
                ['product_name' => 'Demo SSL & Backup Addon', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client2@example.com',
            'product_name' => 'Demo Cloud VPS 8GB',
            'billing_cycle' => 'monthly',
            'status' => 'failed',
            'domain' => 'blueharbor.test',
            'notes' => 'Provisioning failed: insufficient IPs in pool. Retry queued.',
            'items' => [
                ['product_name' => 'Demo Cloud VPS 8GB', 'quantity' => 1],
                ['product_name' => 'Demo SSL & Backup Addon', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client3@example.com',
            'product_name' => 'Demo Reseller Bronze',
            'billing_cycle' => 'monthly',
            'status' => 'active',
            'domain' => 'cedarpoint.test',
            'notes' => 'Reseller account with 25 cPanel slots.',
            'items' => [
                ['product_name' => 'Demo Reseller Bronze', 'quantity' => 1],
                ['product_name' => 'Demo Business Shared Hosting', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client4@example.com',
            'product_name' => 'Demo Dedicated E3 Server',
            'billing_cycle' => 'annual',
            'status' => 'terminated',
            'domain' => 'driftwood.test',
            'notes' => 'Hardware returned to inventory after non-payment.',
            'items' => [
                ['product_name' => 'Demo Dedicated E3 Server', 'quantity' => 1],
                ['product_name' => 'Demo .com Domain Registration', 'quantity' => 1],
            ],
        ],
        [
            'customer_email' => 'client5@example.com',
            'product_name' => 'Demo Business Shared Hosting',
            'billing_cycle' => 'semi_annual',
            'status' => 'active',
            'domain' => 'everline.test',
            'notes' => 'Second site for the marketing team, billed semi-annually.',
            'items' => [
                ['product_name' => 'Demo Business Shared Hosting', 'quantity' => 2],
            ],
        ],
    ];

    public function run(): void
    {
        $this->purgeUnresolvableOrders();

        $customers = $this->customerIdsByEmail();

        if ($customers === []) {
            $this->command?->warn('OrderSeeder: no demo customers found - run CustomerSeeder first. Nothing seeded.');

            return;
        }

        $products = $this->productsByName();
        $actorId = $this->staffActorId();

        if (count(self::ORDERS) < DummyDataConfig::minRows('orders')) {
            throw new RuntimeException(sprintf(
                'OrderSeeder defines %d orders but DummyDataConfig::ROWS requires %d.',
                count(self::ORDERS),
                DummyDataConfig::minRows('orders')
            ));
        }

        foreach (self::ORDERS as $index => $definition) {
            $customerId = $customers[$definition['customer_email']] ?? null;

            if ($customerId === null) {
                throw new RuntimeException(sprintf(
                    'OrderSeeder: no customer for %s - run CustomerSeeder first.',
                    $definition['customer_email']
                ));
            }

            $product = $products[$definition['product_name']] ?? null;

            if ($product === null) {
                throw new RuntimeException(sprintf(
                    'OrderSeeder: no product named "%s" - run ProductSeeder first.',
                    $definition['product_name']
                ));
            }

            $orderId = $this->seedOrder($index, $definition, $customerId, $product, $products);

            $this->seedOrderItems($orderId, $definition['items'], $products);
            $this->seedStatusHistory($orderId, $definition['status'], $actorId);
        }

        $this->report();
    }

    /**
     * Delete any pre-existing order whose `customer_id` or `product_id` does
     * not resolve to a real row (legacy junk rows from before the demo
     * project), together with its `order_items` and `order_status_history`.
     * Orders whose foreign keys DO resolve are never touched, regardless of
     * status, so hand-made orders survive. The whole step runs inside a
     * transaction (a partial failure rolls back) and is a no-op on a fresh
     * database or when nothing is orphaned.
     */
    private function purgeUnresolvableOrders(): void
    {
        DB::transaction(function () {
            $ids = DB::table('orders')
                ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
                ->leftJoin('products', 'products.id', '=', 'orders.product_id')
                ->whereNull('customers.id')
                ->orWhereNull('products.id')
                ->pluck('orders.id')
                ->all();

            if ($ids === []) {
                return;
            }

            DB::table('order_status_history')->whereIn('order_id', $ids)->delete();
            DB::table('order_items')->whereIn('order_id', $ids)->delete();
            DB::table('orders')->whereIn('id', $ids)->delete();
        });
    }

    /**
     * The order total is the sum of ALL its line items (unit price × quantity),
     * and the order quantity is the sum of the item quantities — never just the
     * primary product. Written with updateOrCreate semantics (`seedRow`) so an
     * order created by an earlier run has its corrected values applied in place.
     *
     * @param  array<string, mixed>  $definition
     * @param  array{id: int, price: float}  $product
     * @param  array<string, array{id: int, price: float}>  $products
     */
    private function seedOrder(int $index, array $definition, int $customerId, array $product, array $products): int
    {
        $status = $definition['status'];

        $quantity = 0;
        $total = 0.0;

        foreach ($definition['items'] as $item) {
            $itemProduct = $products[$item['product_name']] ?? null;

            if ($itemProduct === null) {
                throw new RuntimeException(sprintf(
                    'OrderSeeder: order item references unknown product "%s".',
                    $item['product_name']
                ));
            }

            $quantity += $item['quantity'];
            $total += $itemProduct['price'] * $item['quantity'];
        }

        $cycleMonths = Order::CYCLE_MONTHS[$definition['billing_cycle']] ?? 1;
        $recurring = in_array($status, ['active', 'suspended'], true);

        return (int) $this->seedRow('orders', [
            'order_number' => sprintf(self::NUMBER_FORMAT, self::NUMBER_OFFSET + $index + 1),
            'customer_id' => $customerId,
            'product_id' => $product['id'],
            'billing_cycle' => $definition['billing_cycle'],
            'quantity' => $quantity,
            'total' => round($total, 2),
            'status' => $status,
            'domain_name' => $definition['domain'],
            'notes' => $definition['notes'],
            'next_billing_date' => $recurring && $cycleMonths > 0
                ? now()->startOfMonth()->addMonths($cycleMonths)->toDateString()
                : null,
            'last_billing_date' => $recurring
                ? now()->startOfMonth()->toDateString()
                : null,
        ]);
    }

    /**
     * 1-2 line items per order. `order_items` is keyed on
     * (order_id, product_name), so the same product never appears twice on one
     * order and a re-run matches the existing line instead of duplicating it.
     *
     * @param  list<array{product_name: string, quantity: int}>  $items
     * @param  array<string, array{id: int, price: float}>  $products
     */
    private function seedOrderItems(int $orderId, array $items, array $products): void
    {
        foreach ($items as $item) {
            $product = $products[$item['product_name']] ?? null;

            if ($product === null) {
                throw new RuntimeException(sprintf(
                    'OrderSeeder: order item references unknown product "%s".',
                    $item['product_name']
                ));
            }

            $quantity = $item['quantity'];

            $this->seedRowOnce('order_items', [
                'order_id' => $orderId,
                'product_id' => $product['id'],
                'product_name' => $item['product_name'],
                'quantity' => $quantity,
                'unit_price' => $product['price'],
                'total' => round($product['price'] * $quantity, 2),
            ]);
        }
    }

    /**
     * Write the whole transition chain that led to the order's current status,
     * one row per hop: null → pending → paid → ... → current. Each hop is a
     * distinct (order_id, from_status, to_status) triple, which is exactly the
     * natural key, so re-running matches instead of duplicating.
     */
    private function seedStatusHistory(int $orderId, string $status, ?int $actorId): void
    {
        $chain = self::LIFECYCLE[$status] ?? [$status];
        $from = null;

        foreach ($chain as $to) {
            $this->seedRowOnce('order_status_history', [
                'order_id' => $orderId,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $actorId,
                'notes' => self::HOP_NOTES[$to] ?? null,
            ]);

            $from = $to;
        }
    }

    /**
     * The staff user credited with the transitions. Read-only lookup of the
     * InitialDataSeeder admin (never written to), falling back to the lowest
     * user id and finally to null when the table is empty.
     */
    private function staffActorId(): ?int
    {
        $protected = DummyDataConfig::PROTECTED_ROWS['users'] ?? [];

        if ($protected !== []) {
            $id = DB::table('users')->where($protected)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $fallback = DB::table('users')->orderBy('id')->value('id');

        return $fallback !== null ? (int) $fallback : null;
    }

    /**
     * @return array<string, int> users.email => customers.id
     */
    private function customerIdsByEmail(): array
    {
        $emails = array_values(array_unique(array_column(self::ORDERS, 'customer_email')));

        return DB::table('customers')
            ->join('users', 'users.id', '=', 'customers.user_id')
            ->whereIn('users.email', $emails)
            ->pluck('customers.id', 'users.email')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<string, array{id: int, price: float}> products.name => [id, price]
     */
    private function productsByName(): array
    {
        $names = [];

        foreach (self::ORDERS as $order) {
            $names[] = $order['product_name'];

            foreach ($order['items'] as $item) {
                $names[] = $item['product_name'];
            }
        }

        return DB::table('products')
            ->whereIn('name', array_values(array_unique($names)))
            ->get(['id', 'name', 'price'])
            ->keyBy('name')
            ->map(fn ($product) => ['id' => (int) $product->id, 'price' => (float) $product->price])
            ->all();
    }

    private function report(): void
    {
        $this->command?->info('OrderSeeder summary:');

        foreach (['orders', 'order_items', 'order_status_history'] as $table) {
            $count = (int) DB::table($table)->count();
            $minimum = DummyDataConfig::minRows($table);

            $this->command?->info(sprintf(
                '  %-22s %3d  (min %d)  [%s]',
                $table,
                $count,
                $minimum,
                $count >= $minimum ? 'OK' : 'LOW'
            ));
        }
    }
}
