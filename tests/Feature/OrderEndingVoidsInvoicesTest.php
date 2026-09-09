<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An order that ends must stop being collectable, and an active order must be
 * reachable from the admin UI.
 *
 * Cancelling an order used to leave its invoice open and payable, while
 * AdvanceOrderOnPayment only acts on a `pending` order — so a customer could
 * pay for a cancelled order and nothing at all would happen. The webhook made
 * that worse: settlement no longer needs anyone to be watching.
 *
 * Separately, an ACTIVE order had no buttons on its page at all, so a live
 * service could not be suspended, cancelled or terminated from the UI.
 */
class OrderEndingVoidsInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_an_order_voids_its_unpaid_invoice(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_SENT);

        app(OrderService::class)->cancel($order, 'Customer changed their mind');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
        $this->assertStringContainsString('Voided automatically', (string) $invoice->fresh()->notes);
    }

    public function test_terminating_an_order_voids_its_unpaid_invoice(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_OVERDUE, Order::STATUS_ACTIVE);

        app(OrderService::class)->terminate($order, 'Abuse');

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
    }

    public function test_a_draft_invoice_is_voided_too(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_DRAFT);

        app(OrderService::class)->cancel($order);

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()->status);
    }

    public function test_an_invoice_with_money_against_it_is_left_alone(): void
    {
        // Voiding this would misstate revenue that genuinely moved. The refund
        // is a human decision, not a side effect of a status change.
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_PARTIAL);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 40.00,
            'method' => 'razorpay',
            'transaction_id' => 'pay_1',
            'status' => 'completed',
        ]);
        $invoice->update(['paid_amount' => 40.00]);

        app(OrderService::class)->cancel($order);

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->fresh()->status);
    }

    public function test_a_paid_invoice_is_left_alone(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_PAID, Order::STATUS_ACTIVE);
        $invoice->update(['paid_amount' => 100.00, 'paid_at' => now()]);

        app(OrderService::class)->terminate($order);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_suspending_an_order_does_not_void_anything(): void
    {
        // Suspension is reversible — the invoice is still owed.
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_SENT, Order::STATUS_ACTIVE);

        app(OrderService::class)->suspend($order, 'Non-payment');

        $this->assertSame(Invoice::STATUS_SENT, $invoice->fresh()->status);
    }

    public function test_a_customer_cannot_start_paying_a_voided_invoice(): void
    {
        [$order, $invoice] = $this->orderWithInvoice(Invoice::STATUS_SENT);
        $customerUser = $invoice->customer->user;

        app(OrderService::class)->cancel($order);

        $this->actingAs($customerUser)
            ->get(route('client.invoices.pay', $invoice))
            ->assertRedirect(route('client.invoices.show', $invoice))
            ->assertSessionHas('warning');

        $this->actingAs($customerUser)
            ->post(route('client.invoices.pay.purchase', $invoice), ['gateway' => 'razorpay'])
            ->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_an_active_order_can_be_suspended_cancelled_or_terminated_from_the_admin_page(): void
    {
        [$order] = $this->orderWithInvoice(Invoice::STATUS_PAID, Order::STATUS_ACTIVE);

        $html = $this->admin()->get(route('admin.orders.show', $order))->assertOk()->getContent();

        $this->assertStringContainsString('Suspend', $html);
        $this->assertStringContainsString('Terminate', $html);
        $this->assertStringContainsString('Cancel', $html);

        // And the buttons actually work, not just render.
        $this->admin()
            ->put(route('admin.orders.status', $order), ['status' => Order::STATUS_SUSPENDED])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame(Order::STATUS_SUSPENDED, $order->fresh()->status);
    }

    public function test_a_terminated_order_offers_nothing_further(): void
    {
        [$order] = $this->orderWithInvoice(Invoice::STATUS_PAID, Order::STATUS_ACTIVE);
        app(OrderService::class)->terminate($order);

        $html = $this->admin()->get(route('admin.orders.show', $order->fresh()))->assertOk()->getContent();

        $this->assertStringNotContainsString('order-status-cancelled', $html);
        $this->assertStringNotContainsString('order-status-suspended', $html);
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * @return array{0: Order, 1: Invoice}
     */
    private function orderWithInvoice(string $invoiceStatus, string $orderStatus = Order::STATUS_PENDING): array
    {
        $user = User::factory()->create();
        $customer = Customer::create(['user_id' => $user->id, 'status' => 'active']);

        $product = Product::create([
            'name' => 'Shared Hosting',
            'price' => 100,
            'provisioning_module' => 'manual',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.Str::upper(Str::random(8)),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 100.00,
            'status' => $orderStatus,
        ]);

        $invoice = Invoice::create([
            'invoice_no' => 'INV-'.Str::upper(Str::random(8)),
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'amount' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'status' => $invoiceStatus,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        return [$order, $invoice];
    }

    private function admin(): self
    {
        static $user = null;

        if ($user === null) {
            $user = User::factory()->create(['role' => 'admin']);

            $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

            foreach (['orders.view', 'orders.edit'] as $name) {
                $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
                $role->permissions()->syncWithoutDetaching($permission->id);
            }

            $user->assignRole('admin');
        }

        return $this->actingAs($user);
    }
}
