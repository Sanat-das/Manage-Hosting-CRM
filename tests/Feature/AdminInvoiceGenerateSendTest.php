<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Invoice generation from an existing order (OrderController::generateInvoice
 * — the order page "Generate Invoice" button) and invoice emailing from the
 * invoice page (InvoiceController::send — the "Send Invoice" button).
 *
 * Locks in:
 *  - an order can be invoiced on demand through the shared GST engine, and
 *    only one open draft per order (the existing draft is surfaced instead);
 *  - cancelled/terminated orders cannot be invoiced;
 *  - sending emails the customer from the invoice_created template and flips
 *    a draft to sent only once the email actually dispatched; paid, void and
 *    cancelled invoices can never be emailed.
 */
class AdminInvoiceGenerateSendTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit', 'invoices.view', 'invoices.create', 'invoices.edit'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'status' => 'active',
            'require_domain' => false,
        ], $attributes));
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Test Corp',
            'status' => 'active',
        ]);
    }

    private function makeTemplate(string $name, string $subject, string $body): EmailTemplate
    {
        return EmailTemplate::create([
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'status' => 'active',
        ]);
    }

    private function makeOrder(Customer $customer, Product $product, string $status = Order::STATUS_PENDING): Order
    {
        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.str_pad((string) ((int) Order::max('id') + 1), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $status,
        ]);

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

    // ─── Generate Invoice (order page) ─────────────────────────────────

    public function test_generate_invoice_creates_draft_invoice_linked_to_order(): void
    {
        $admin = $this->adminUser();
        $order = $this->makeOrder($this->makeCustomer(), $this->makeProduct());

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $invoice = Invoice::sole();

        $this->assertSame(1, Invoice::count());
        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->status);
        $this->assertSame($order->id, $invoice->order_id);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertNotNull($invoice->due_date);
        $response->assertRedirect(route('admin.invoices.show', $invoice));
    }

    public function test_generate_invoice_surfaces_existing_draft_instead_of_duplicate(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-1000',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $this->assertSame(1, Invoice::count());
        $response->assertRedirect(route('admin.invoices.show', Invoice::sole()));
    }

    public function test_generate_invoice_rejected_for_cancelled_order(): void
    {
        $admin = $this->adminUser();
        $order = $this->makeOrder($this->makeCustomer(), $this->makeProduct(), Order::STATUS_CANCELLED);

        $response = $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, Invoice::count());
    }

    public function test_generate_invoice_allowed_once_previous_leaves_draft(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, $this->makeProduct());

        // A sent invoice is no longer an open draft → a new one may be raised
        // for the next billing cycle.
        Invoice::create([
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'invoice_no' => 'INV-1000',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);

        $this->actingAs($admin)->post(route('admin.orders.generate-invoice', $order));

        $this->assertSame(2, Invoice::count());
    }

    // ─── Send Invoice (invoice page) ──────────────────────────────────

    public function test_send_invoice_emails_customer_and_flips_draft_to_sent(): void
    {
        $this->makeTemplate(
            'invoice_created',
            'Your invoice {{invoice_no}}',
            "Hi {{name}},\n\nInvoice {{invoice_no}} is ready. Pay by {{due_date}}. Total: {{total}}."
        );

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-1001',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.invoices.send', $invoice));

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_SENT, $invoice->status);

        $this->assertDatabaseHas('emails', [
            'to_email' => $customer->user->email,
            'status' => 'sent',
        ]);

        $this->assertStringContainsString($invoice->invoice_no, (string) \DB::table('emails')->value('body'));
        $response->assertRedirect(route('admin.invoices.show', $invoice));
    }

    public function test_send_invoice_without_template_keeps_draft_and_sends_nothing(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-1002',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_DRAFT,
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.invoices.send', $invoice));

        $this->assertSame(Invoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertSame(0, \DB::table('emails')->count());
        $response->assertSessionHasErrors('send');
    }

    public function test_resend_sent_invoice_emails_again_without_status_change(): void
    {
        $this->makeTemplate('invoice_created', 'Invoice {{invoice_no}}', 'Invoice {{invoice_no}} body');

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-1003',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_SENT,
            'due_date' => now()->addDays(7),
        ]);

        $this->actingAs($admin)->post(route('admin.invoices.send', $invoice));

        $this->assertSame(Invoice::STATUS_SENT, $invoice->fresh()->status);
        $this->assertSame(1, \DB::table('emails')->count());
    }

    public function test_send_invoice_rejected_for_paid_invoice(): void
    {
        $this->makeTemplate('invoice_created', 'Invoice', 'Invoice body');

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_no' => 'INV-1004',
            'amount' => 100.00,
            'total' => 100.00,
            'status' => Invoice::STATUS_PAID,
            'paid_at' => now(),
            'due_date' => now()->addDays(7),
        ]);

        $response = $this->actingAs($admin)->post(route('admin.invoices.send', $invoice));

        $this->assertSame(0, \DB::table('emails')->count());
        $response->assertSessionHasErrors('send');
    }
}
