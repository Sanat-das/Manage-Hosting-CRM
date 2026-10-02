<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPricing;
use App\Models\ProductUpgradePath;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Billing\UpgradeRequestService;
use App\Services\OrderNumberService;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Concurrent double-submit hardening for the upgrade lifecycle: the
 * open-request guard in place() and the status guards in approve()/cancel()
 * run inside the same transaction as their writes, so a double-submit cannot
 * double-create money rows. SQLite executes these sequentially, so each test
 * drives the second call through the frozen sequential guard.
 */
class UpgradeRequestConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // spatie registers settings classes as container-scoped singletons;
        // flush them so each test resolves a fresh instance for its own DB.
        app()->forgetScopedInstances();

        // AppSettings caches the legacy settings table statically for the
        // request lifetime; reset so each test reads freshly-seeded rows.
        $ref = new \ReflectionClass(AppSettings::class);
        $prop = $ref->getProperty('cache');
        $prop->setValue(null, null);
    }

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Upgrade Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(string $name, float $price): Product
    {
        return Product::create([
            'name' => $name,
            'is_bundle' => false,
            'price' => $price,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ]);
    }

    private function price(Product $product, float $price, float $setupFee = 0, string $cycle = 'monthly'): ProductPricing
    {
        return $product->pricing()->create([
            'billing_cycle' => $cycle,
            'price' => $price,
            'setup_fee' => $setupFee,
        ]);
    }

    private function makeOrder(Product $from, float $unitPrice, array $overrides = []): Order
    {
        $customer = $this->makeCustomer();
        $due = CarbonImmutable::today('Asia/Kolkata')->addDays(30)->toDateString();
        $last = CarbonImmutable::today('Asia/Kolkata')->toDateString();

        $order = Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $from->id,
            'order_number' => app(OrderNumberService::class)->next('ORD'),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => $unitPrice,
            'status' => Order::STATUS_ACTIVE,
            'next_billing_date' => $due,
            'last_billing_date' => $last,
        ], $overrides));

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $from->id,
            'product_name' => $from->name,
            'billing_cycle' => $order->billing_cycle,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'total' => $unitPrice,
            'next_billing_date' => $due,
        ]);

        return $order;
    }

    private function enablePath(Product $from, Product $to): void
    {
        ProductUpgradePath::create([
            'from_product_id' => $from->id,
            'to_product_id' => $to->id,
            'enabled' => true,
        ]);
    }

    private function upgradeScenario(float $fromPrice, float $toPrice, array $orderOverrides = []): array
    {
        $from = $this->makeProduct('Basic Hosting', $fromPrice);
        $this->price($from, $fromPrice);
        $to = $this->makeProduct('Pro Hosting', $toPrice);
        $this->price($to, $toPrice);
        $this->enablePath($from, $to);

        return [$this->makeOrder($from, $fromPrice, $orderOverrides), $from, $to];
    }

    private function service(): UpgradeRequestService
    {
        return app(UpgradeRequestService::class);
    }

    public function test_two_concurrent_places_create_one_request(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);

        // SQLite's grammar drops lockForUpdate, so the keyword is not
        // observable here; the deterministic proof is transaction placement —
        // the guard SELECT and the INSERT must execute at the same (inner)
        // transaction depth during a single place().
        $log = [];
        DB::listen(function ($query) use (&$log): void {
            $log[] = [$query->sql, DB::transactionLevel()];
        });

        $this->service()->place($order, $to);
        $this->assertSame(1, UpgradeRequest::where('order_id', $order->id)->count());

        $guard = collect($log)->first(
            fn (array $entry): bool => str_contains($entry[0], 'upgrade_requests')
                && str_contains($entry[0], 'select'),
        );
        $insert = collect($log)->first(
            fn (array $entry): bool => str_contains($entry[0], 'insert into "upgrade_requests"'),
        );
        $this->assertNotNull($guard, 'Expected the open-request guard query in the SQL log.');
        $this->assertNotNull($insert, 'Expected the upgrade_requests insert in the SQL log.');
        $this->assertGreaterThan(0, $guard[1], 'The open-request guard must run inside a transaction.');
        $this->assertSame($guard[1], $insert[1], 'The guard and the insert must share one transaction.');

        try {
            $this->service()->place($order, $to);
            $this->fail('The second place() should have been rejected.');
        } catch (DomainException $e) {
            $this->assertSame('Previous upgrade for this service is still pending or unpaid.', $e->getMessage());
        }

        $this->assertSame(1, UpgradeRequest::where('order_id', $order->id)->count());
    }

    public function test_two_concurrent_approves_create_one_invoice(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);

        $this->service()->approve($request);
        $invoice = Invoice::where('order_id', $order->id)->sole();

        try {
            $this->service()->approve($request->fresh());
            $this->fail('The second approve() should have been rejected.');
        } catch (DomainException $e) {
            $this->assertSame('Upgrade is not pending.', $e->getMessage());
        }

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame((int) $invoice->id, (int) $request->fresh()->invoice_id);
    }

    public function test_two_concurrent_cancels_void_invoice_once(): void
    {
        [$order, $from, $to] = $this->upgradeScenario(100, 200);
        $request = $this->service()->place($order, $to);
        $this->service()->approve($request);
        $invoice = Invoice::where('order_id', $order->id)->sole();

        $this->service()->cancel($request->fresh());

        try {
            $this->service()->cancel($request->fresh());
            $this->fail('The second cancel() should have been rejected.');
        } catch (DomainException $e) {
            $this->assertSame('Upgrade is not pending.', $e->getMessage());
        }

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertSame(UpgradeRequest::STATUS_CANCELLED, UpgradeRequest::find($request->id)->status);
    }
}
