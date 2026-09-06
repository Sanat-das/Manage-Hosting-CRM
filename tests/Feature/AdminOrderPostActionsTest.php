<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Post-order actions on the admin "New Order" form: the Order Confirmation /
 * Generate Invoice / Send Email checkboxes wired through
 * OrderController::store.
 *
 * Locks in:
 *  - "Generate Invoice" defaults from the auto_generate_invoice setting and
 *    can be switched per-form (no invoice row when unticked);
 *  - the confirmation / invoice emails are sent from the admin-managed
 *    templates (order_confirmation / invoice_created) to the customer's user
 *    email via the SendEmail job (sync queue → emails log row), and skipped
 *    quietly when the template is missing.
 */
class AdminOrderPostActionsTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['orders.view', 'orders.create', 'orders.edit'] as $permissionName) {
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

    private function storePayload(Customer $customer, Product $product, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => 100.00,
        ], $extra);
    }

    public function test_unchecked_generate_invoice_skips_invoice_creation(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // An unchecked HTML checkbox submits no key at all — this is exactly
        // how an unticked "Generate Invoice" arrives at the controller.
        $this->actingAs($admin)->post(route('admin.orders.store'), $this->storePayload($customer, $product));

        $this->assertSame(1, Order::count());
        $this->assertSame(0, Invoice::count());
        // No templates seeded → the confirmation/invoice emails are skipped.
        $this->assertSame(0, \DB::table('emails')->count());
    }

    public function test_ticked_generate_invoice_creates_invoice_without_templates(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['generate_invoice' => 1])
        );

        $this->assertSame(1, Order::count());
        $this->assertSame(1, Invoice::count());
        // No templates seeded → no emails.
        $this->assertSame(0, \DB::table('emails')->count());
    }

    public function test_generate_invoice_zero_skips_invoice_creation(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['generate_invoice' => 0])
        );

        $this->assertSame(1, Order::count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_setting_no_does_not_block_explicit_generate_invoice_tick(): void
    {
        Setting::create(['setting_key' => 'auto_generate_invoice', 'setting_value' => 'no']);

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        // The setting only drives the checkbox's initial state on the form —
        // an explicit tick always generates the invoice.
        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['generate_invoice' => 1])
        );
        $this->assertSame(1, Invoice::count());
    }

    public function test_send_confirmation_emails_customer_from_template(): void
    {
        $this->makeTemplate(
            'order_confirmation',
            'Order {{order_no}} confirmed',
            "Hi {{name}},\n\nYour order {{order_no}} has been received. Total: {{total}}.\n\nThanks"
        );

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['send_confirmation' => 1])
        );

        $order = Order::sole();
        $email = $customer->user->email;

        $this->assertDatabaseHas('emails', [
            'to_email' => $email,
            'status' => 'sent',
        ]);

        $this->assertSame("Order {$order->order_number} confirmed", \DB::table('emails')->value('subject'));
        $this->assertStringContainsString((string) $order->order_number, (string) \DB::table('emails')->value('body'));
    }

    public function test_send_confirmation_without_template_sends_nothing(): void
    {
        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['send_confirmation' => 1])
        );

        $this->assertSame(0, \DB::table('emails')->count());
    }

    public function test_send_invoice_emails_customer_when_invoice_generated(): void
    {
        $this->makeTemplate(
            'invoice_created',
            'Your demo invoice is ready',
            "Hi {{name}},\n\nYour invoice {{invoice_no}} is ready. Please pay by {{due_date}}. Total: {{total}}."
        );

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['generate_invoice' => 1, 'send_invoice' => 1])
        );

        $invoice = Invoice::sole();

        $this->assertDatabaseHas('emails', [
            'to_email' => $customer->user->email,
            'status' => 'sent',
        ]);

        $body = (string) \DB::table('emails')->value('body');
        $this->assertStringContainsString($invoice->invoice_no, $body);
    }

    public function test_send_invoice_without_generate_invoice_sends_nothing(): void
    {
        $this->makeTemplate('invoice_created', 'Invoice', 'Invoice {{invoice_no}}');

        $admin = $this->adminUser();
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->actingAs($admin)->post(
            route('admin.orders.store'),
            $this->storePayload($customer, $product, ['generate_invoice' => 0, 'send_invoice' => 1])
        );

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, \DB::table('emails')->count());
    }
}
