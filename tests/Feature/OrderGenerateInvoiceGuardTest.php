<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\AddOnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Guard on the order page "Generate Invoice" action
 * (OrderController::generateInvoice): every order item — add-on rows included —
 * goes onto the generated invoice, so an order holding a non-draft live invoice
 * (e.g. the `sent` invoice raised by an add-on attach) must not be invoiced
 * again. An existing draft is surfaced by redirecting to it instead of
 * duplicating it, and void/cancelled invoices are dead documents that must not
 * block a fresh one.
 */
class OrderGenerateInvoiceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_invoice_surfaces_an_existing_draft_instead_of_duplicating(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        $draft = $this->makeInvoice($order, $customer, Invoice::STATUS_DRAFT);

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        // Surfaced, not refused: the draft already carries every order item.
        $response->assertSessionHasNoErrors();
        $this->assertSame(1, Invoice::count());
        $response->assertRedirect(route('admin.invoices.show', $draft));
    }

    public function test_generate_invoice_is_refused_when_a_sent_attach_invoice_exists(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $order = $this->makeOrder($customer, $product, Order::STATUS_ACTIVE);
        $parent = OrderItem::where('order_id', $order->id)->sole();

        // The add-on attach raises its own `sent` invoice — exactly the case
        // that would be re-billed by a second generate-invoice run.
        app(AddOnService::class)->attach($order, $parent, $this->makeAddon($product));

        $this->assertSame(Invoice::STATUS_SENT, Invoice::sole()->status);

        $response = $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.generate-invoice', $order));

        $this->assertRefused($response, $order);
    }

    public function test_generate_invoice_succeeds_when_the_only_invoice_is_void(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        $this->makeInvoice($order, $customer, Invoice::STATUS_VOID);

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $response->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $draft = Invoice::where('order_id', $order->id)->where('status', Invoice::STATUS_DRAFT)->sole();
        $response->assertRedirect(route('admin.invoices.show', $draft));
    }

    public function test_generate_invoice_succeeds_when_the_only_invoice_is_cancelled(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        $this->makeInvoice($order, $customer, Invoice::STATUS_CANCELLED);

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $response->assertSessionHasNoErrors();

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::where('order_id', $order->id)->where('status', Invoice::STATUS_DRAFT)->count());
    }

    public function test_generate_invoice_succeeds_when_the_order_has_no_invoices(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $response->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame((int) $order->id, (int) $invoice->order_id);
        $response->assertRedirect(route('admin.invoices.show', $invoice));
    }

    private function assertRefused(TestResponse $response, Order $order): void
    {
        $response->assertRedirect(route('admin.orders.show', $order));
        $response->assertSessionHasErrors('error');

        $this->assertSame(1, Invoice::count(), 'A refused generate-invoice run must not add an invoice.');
        $this->assertStringContainsString(
            'An invoice already exists for this order',
            (string) session('errors')->first('error')
        );
    }

    private function adminUser(array $permissionNames = ['orders.view', 'invoices.view', 'invoices.create']): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('.', ' ', $name))]
            );
            $permissionIds[] = $permission->id;
        }

        $adminRole->permissions()->sync($permissionIds);
        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Generate Invoice Guard Corp',
            'status' => 'active',
        ]);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ], $attributes));
    }

    private function makeAddon(Product $product): ProductAddon
    {
        return ProductAddon::create([
            'product_id' => $product->id,
            'name' => 'Extra Storage',
            'billing_cycle' => 'monthly',
            'price' => 25.00,
            'setup_fee' => 0,
            'status' => 'active',
        ]);
    }

    private function makeOrder(Customer $customer, Product $product, string $status = Order::STATUS_PENDING): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $status,
        ]);

        // One billable line: generate-invoice puts every item on the invoice,
        // which is what makes a second run a double-billing hazard.
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);

        return $order;
    }

    private function makeInvoice(Order $order, Customer $customer, string $status): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount' => 100.00,
            'total' => 100.00,
            'status' => $status,
            'due_date' => now()->addDays(7),
        ]);
    }
}
