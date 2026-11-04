<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\HostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The overdue-service staff alert: hosting:overdue-check finds accounts past
 * their effective renewal due date (COALESCE(next_due_date,
 * order.next_billing_date)), notifies each active admin/staff/support user
 * once per account per due date, and sends the staff digest once per day.
 * Terminated and future-dated accounts are ignored.
 */
class HostingOverdueCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();
    }

    public function test_an_overdue_active_account_alerts_staff_and_sends_the_digest(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'staff', 'status' => 'active']);

        $this->makeTemplate('overdue_renewal_admin', '{{overdue_count}} overdue', '{{overdue_html}}');

        $due = now()->subDays(2)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $account = $this->makeAccount($customer, $product, $order, ['next_due_date' => $due]);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        foreach ([$admin, $staff] as $staffUser) {
            $this->assertSame(1, $staffUser->fresh()->notifications()->count());

            $notification = $staffUser->fresh()->notifications()->first();
            $this->assertSame('service.overdue', $notification->data['type']);
            $this->assertSame($account->id, $notification->data['hosting_account_id']);
            $this->assertSame($order->order_number, $notification->data['order_no']);
            $this->assertSame($due, $notification->data['due_date']);
        }

        $this->assertDatabaseHas('emails', [
            'to_email' => $admin->email,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('emails', [
            'to_email' => $staff->email,
            'status' => 'sent',
        ]);
    }

    public function test_a_second_run_the_same_day_sends_nothing_more(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->makeTemplate('overdue_renewal_admin', '{{overdue_count}} overdue', '{{overdue_html}}');

        $due = now()->subDays(2)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeAccount($customer, $product, $order, ['next_due_date' => $due]);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);
        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        $this->assertSame(1, $admin->fresh()->notifications()->count(), 'A second run must not re-notify for the same due date.');
        $this->assertSame(
            1,
            DB::table('emails')->where('to_email', $admin->email)->count(),
            'The second run must not re-send the digest.'
        );
    }

    public function test_a_terminated_account_and_a_future_account_are_not_notified(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->makeTemplate('overdue_renewal_admin', '{{overdue_count}} overdue', '{{overdue_html}}');

        $pastDue = now()->subDays(5)->toDateString();
        $pastOrder = $this->makeOrder($customer, $product, ['next_billing_date' => $pastDue]);
        $this->makeAccount($customer, $product, $pastOrder, [
            'status' => HostingService::STATUS_TERMINATED,
            'next_due_date' => $pastDue,
        ]);

        $futureDue = now()->addDays(10)->toDateString();
        $futureOrder = $this->makeOrder($customer, $product, ['next_billing_date' => $futureDue]);
        $this->makeAccount($customer, $product, $futureOrder, ['next_due_date' => $futureDue]);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        $this->assertSame(0, $admin->fresh()->notifications()->count());
        $this->assertSame(0, DB::table('emails')->where('to_email', $admin->email)->count());
    }

    /**
     * COALESCE fallback: an account with no next_due_date of its own is judged
     * by the order's next_billing_date — the shape of the example production
     * case (order created after the ladder's rungs had passed).
     */
    public function test_an_account_overdue_via_the_order_next_billing_date_is_notified(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->makeTemplate('overdue_renewal_admin', '{{overdue_count}} overdue', '{{overdue_html}}');

        $due = now()->subDays(1)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $account = $this->makeAccount($customer, $product, $order);

        $this->assertNull($account->next_due_date);

        $this->artisan('hosting:overdue-check')->assertExitCode(0);

        $notification = $admin->fresh()->notifications()->first();
        $this->assertNotNull($notification, 'An account overdue only via the order date must be notified.');
        $this->assertSame('service.overdue', $notification->data['type']);
        $this->assertSame($account->id, $notification->data['hosting_account_id']);
        $this->assertSame($due, $notification->data['due_date']);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Overdue Check Corp',
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

    private function makeOrder(Customer $customer, Product $product, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.date('Y').'-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => (float) $product->price,
            'status' => Order::STATUS_ACTIVE,
        ], $overrides));
    }

    private function makeAccount(Customer $customer, Product $product, ?Order $order, array $overrides = []): HostingAccount
    {
        return HostingAccount::create(array_merge([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order?->id,
            'status' => HostingService::STATUS_ACTIVE,
        ], $overrides));
    }

    private function makeTemplate(string $name, string $subject, string $body): EmailTemplate
    {
        // 2026_11_03_000001 backfills the overdue template, so a fresh test
        // database already holds the row: take ownership of the content
        // instead of colliding on the unique name.
        return EmailTemplate::updateOrCreate(
            ['name' => $name],
            ['subject' => $subject, 'body' => $body, 'status' => 'active'],
        );
    }
}
