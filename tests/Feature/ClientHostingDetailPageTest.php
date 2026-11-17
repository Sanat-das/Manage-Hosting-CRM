<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Domain;
use App\Models\HostingAccount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductModule;
use App\Models\Server;
use App\Models\User;
use App\Services\Provisioning\ComputeTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client service detail page (/client/hosting/{id}).
 *
 * A service must never be shown a VM surface it does not have, never be told
 * to do something the platform cannot do, and must link the money (the order
 * and its newest invoice) and the domain it belongs to.
 */
class ClientHostingDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): Customer
    {
        return Customer::create([
            'user_id' => User::factory()->create()->id,
            'company' => 'Detail Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
        ], $attributes));
    }

    private function makeOrder(Customer $customer, Product $product): Order
    {
        return Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => Order::STATUS_ACTIVE,
        ]);
    }

    private function makeServiceLine(Order $order, Product $product): OrderItem
    {
        return OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
            'billing_cycles_count' => 1,
        ]);
    }

    private function makeInvoice(Order $order, string $invoiceNo, string $status = Invoice::STATUS_SENT, ?string $description = null): Invoice
    {
        $invoice = Invoice::create([
            'invoice_no' => $invoiceNo,
            'customer_id' => $order->customer_id,
            'order_id' => $order->id,
            'amount' => 100.00,
            'total' => 100.00,
            'status' => $status,
            'due_date' => now()->addDays(30),
        ]);

        // Real invoices carry a line: the service line's description is the
        // product name, an add-on charge carries the add-on's name.
        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $order->product_id,
            'description' => $description
                ?? ($order->items()->whereNull('product_addon_id')->value('product_name') ?? 'Service'),
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        return $invoice;
    }

    private function makeAccount(
        Customer $customer,
        Product $product,
        ?Order $order = null,
        ?Server $server = null,
        string $domain = 'example.com'
    ): HostingAccount {
        return HostingAccount::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order?->id,
            'server_id' => $server?->id,
            'username' => 'hostuser',
            'domain' => $domain,
            'host_name' => 'demo-'.str()->lower(str()->random(6)),
            'status' => 'active',
        ]);
    }

    public function test_a_service_without_a_host_shows_no_vm_surface_and_offers_support(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        // The service has no host, so no VM surface at all.
        $response->assertDontSee('Start VM');
        $response->assertDontSee('Provision VM');
        $response->assertDontSee('Reset Administrator password');

        // The next step instead: support.
        $response->assertSee('Next steps');
        $response->assertSee('Your service is active. If something is not working, our team can take a look.');
        $response->assertSee('Contact support');
        $response->assertSee(route('client.tickets.create'), false);
    }

    public function test_a_compute_service_still_shows_the_vm_controls(): void
    {
        // Regression guard for the "a compute account always has a host" rule:
        // an account WITH a compute host keeps the whole VM surface.
        $this->assertTrue(ComputeTemplateCatalog::supports('hyperv'));

        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'HV Compute']);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'config' => [],
        ]);
        $server = Server::create([
            'name' => 'hv-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'status' => 'active',
        ]);
        $account = $this->makeAccount($customer, $product, null, $server);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        $response->assertSee('Quick Actions');
        $response->assertSee('Start VM');
        $response->assertSee('Reset Administrator password');
        $response->assertDontSee('Next steps');
    }

    public function test_the_billing_card_links_the_order_and_the_newest_invoice(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);

        $older = $this->makeInvoice($order, 'INV-0001');
        $newest = $this->makeInvoice($order, 'INV-0002');

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        $response->assertSee(route('client.orders.show', $order), false);
        $response->assertSee(route('client.invoices.show', $newest->id), false);
        $response->assertSee('INV-0002');
        // Only the newest invoice of the order is linked.
        $response->assertDontSee(route('client.invoices.show', $older->id), false);
    }

    public function test_an_addon_charge_is_not_the_service_invoice(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order);

        $serviceInvoice = $this->makeInvoice($order, 'INV-SERVICE');
        // An add-on purchase raises its own invoice on the same order.
        $addonInvoice = $this->makeInvoice($order, 'INV-ADDON', Invoice::STATUS_SENT, 'Extra Storage — Monthly');

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        // The card links the document that bills THIS service, not the add-on
        // charge raised later on the same order.
        $response->assertSee(route('client.invoices.show', $serviceInvoice->id), false);
        $response->assertDontSee(route('client.invoices.show', $addonInvoice->id), false);
    }

    public function test_the_domain_row_links_the_customers_own_domain(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $account = $this->makeAccount($customer, $product);
        $domain = Domain::create([
            'customer_id' => $customer->id,
            'name' => 'example.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk();

        $response->assertSee(route('client.domains.show', $domain->id), false);
    }

    public function test_the_account_details_card_names_the_service_and_the_missing_host(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $account = $this->makeAccount($customer, $product);

        $content = (string) $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<td>#'.$account->id.'</td>', $content);
        $this->assertStringContainsString('No dedicated server is assigned to this service.', $content);
    }

    public function test_the_money_card_sits_right_after_the_account_details_card(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct(['name' => 'HV Compute']);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'config' => [],
        ]);
        $server = Server::create([
            'name' => 'hv-1',
            'ip_address' => '10.0.0.9',
            'server_type' => 'hyperv',
            'status' => 'active',
        ]);
        $order = $this->makeOrder($customer, $product);
        $this->makeServiceLine($order, $product);
        $account = $this->makeAccount($customer, $product, $order, $server);

        $content = (string) $this->actingAs($customer->user)
            ->get(route('client.hosting.show', $account->id))
            ->assertOk()
            ->getContent();

        // The card order the customer reads: the service, its money, the VM
        // provisioning, then the actions.
        $order_ = [];
        foreach (['Account Details', 'Billing', 'Provisioning', 'Quick Actions'] as $marker) {
            $position = strpos($content, $marker);
            $this->assertNotFalse($position, $marker.' is missing from the page');
            $order_[$marker] = $position;
        }

        $sorted = $order_;
        asort($sorted);

        $this->assertSame(array_keys($order_), array_keys($sorted));
    }
}
