<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\RenewalReminderService;
use App\Settings\BillingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Slice B — the renewal reminder.
 *
 * renewals:remind emails the client from the renewal_reminder template, raises
 * the portal notification, sends the admin digest, and stamps the order's
 * per-cycle reminder marker; the reminder window comes from the
 * renewal_reminder_days BillingSettings key unless --days overrides it.
 */
class RenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie settings are container-scoped; resolve a fresh instance.
        app()->forgetScopedInstances();
    }

    public function test_the_command_reminds_the_client_and_digests_the_admin(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');
        $this->makeTemplate('renewal_reminder_admin', '{{renewal_count}} renewals', '{{renewals_html}}');

        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $this->artisan('renewals:remind')->assertExitCode(0);

        $this->assertDatabaseHas('emails', [
            'to_email' => $customer->user->email,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('emails', [
            'to_email' => $admin->email,
            'status' => 'sent',
        ]);

        $this->assertSame(1, $customer->user->fresh()->notifications()->count());
        $notification = $customer->user->fresh()->notifications()->first();
        $this->assertSame('renewal.upcoming', $notification->data['type']);

        $order->refresh();
        $this->assertSame($due, $order->renewal_reminder_for->toDateString());
        $this->assertNotNull($order->renewal_reminder_sent_at);
    }

    public function test_a_second_run_does_not_remind_the_same_cycle_again(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');

        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $this->artisan('renewals:remind')->assertExitCode(0);
        $this->artisan('renewals:remind')->assertExitCode(0);

        $this->assertSame(1, DB::table('emails')->where('to_email', $customer->user->email)->count());
        $this->assertSame(1, $customer->user->fresh()->notifications()->count());
    }

    /**
     * The once-per-cycle guard lives in the service, not only in the command:
     * a second caller inside the same cycle must be a successful no-op that
     * sends nothing more.
     */
    public function test_the_service_is_idempotent_for_the_cycle(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');

        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $service = app(RenewalReminderService::class);

        $this->assertTrue($service->remind($order));
        $this->assertSame(1, DB::table('emails')->where('to_email', $customer->user->email)->count());

        // A fresh instance, so the guard must read the persisted marker.
        $this->assertTrue($service->remind($order->fresh()));

        $this->assertSame(
            1,
            DB::table('emails')->where('to_email', $customer->user->email)->count(),
            'A second reminder in the same cycle must dispatch no additional email.'
        );

        $this->assertSame($due, $order->fresh()->renewal_reminder_for->toDateString());
    }

    /**
     * The admin digest carries its own once-per-day guard: a second callee on
     * the same calendar day reports zero recipients and sends nothing.
     */
    public function test_the_admin_digest_is_sent_once_per_day(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->makeTemplate('renewal_reminder_admin', '{{renewal_count}} renewals', '{{renewals_html}}');

        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $service = app(RenewalReminderService::class);
        $orders = collect([$order]);

        $this->assertSame(1, $service->sendAdminDigest($orders));
        $this->assertSame(0, $service->sendAdminDigest($orders));

        $this->assertSame(
            1,
            DB::table('emails')->where('to_email', $admin->email)->count(),
            'The second digest invocation must not re-send to the same admin.'
        );
    }

    /**
     * Bookkeeping must never downgrade a dispatched reminder: a marker write
     * that fails after the mail left still reports success.
     */
    public function test_a_failed_marker_write_does_not_fail_the_reminder(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');

        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        // Stand-in for a failed marker write (DB outage, constraint, deadlock):
        // the marker save is the only Order write remind() performs.
        Order::saving(function (Order $saving): void {
            if ($saving->isDirty('renewal_reminder_for')) {
                throw new RuntimeException('simulated bookkeeping failure');
            }
        });

        $this->assertTrue(app(RenewalReminderService::class)->remind($order));
        $this->assertSame(1, DB::table('emails')->where('to_email', $customer->user->email)->count());
        $this->assertNull($order->fresh()->renewal_reminder_for);
    }

    public function test_an_order_outside_the_window_is_not_reminded(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');

        // 30 days out — one day past the 7-day default window.
        $due = now()->addDays(30)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $this->artisan('renewals:remind')->assertExitCode(0);

        $this->assertSame(0, DB::table('emails')->where('to_email', $customer->user->email)->count());
        $this->assertSame(0, $customer->user->fresh()->notifications()->count());

        $order->refresh();
        $this->assertNull($order->renewal_reminder_for);
        $this->assertNull($order->renewal_reminder_sent_at);
    }

    public function test_the_days_option_overrides_the_configured_window(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();

        $this->makeTemplate('renewal_reminder', 'Renewal on {{renewal_date}}', 'Hi {{name}}, {{product_name}} renews on {{renewal_date}}.');

        // Inside the 7-day default, outside the 1-day override.
        $due = now()->addDays(3)->toDateString();
        $order = $this->makeOrder($customer, $product, ['next_billing_date' => $due]);
        $this->makeItem($order, $product, ['next_billing_date' => $due]);

        $this->artisan('renewals:remind', ['--days' => 1])->assertExitCode(0);

        $this->assertSame(0, DB::table('emails')->where('to_email', $customer->user->email)->count());
        $this->assertSame(0, $customer->user->fresh()->notifications()->count());
    }

    /**
     * The window is a typed BillingSettings key: the admin form must persist it
     * through the settings class, and the frozen 0–90 validation must be live.
     */
    public function test_admin_settings_form_persists_the_reminder_window_as_a_typed_billing_key(): void
    {
        $admin = $this->makeSettingsAdmin();

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'settings' => ['renewal_reminder_days' => '3'],
            ])
            ->assertRedirect();

        $this->assertSame(3, app(BillingSettings::class)->renewal_reminder_days);

        $this->actingAs($admin)
            ->post(route('admin.settings.update'), [
                'settings' => ['renewal_reminder_days' => '120'],
            ])
            ->assertSessionHasErrors('settings.renewal_reminder_days');
    }

    private function makeSettingsAdmin(): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);

        foreach (['settings.view', 'settings.manage'] as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
            $adminRole->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->assignRole('admin');

        return $user;
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();
        $user->assignRole('client');

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Renewal Reminder Corp',
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

    private function makeItem(Order $order, Product $product, array $overrides = []): OrderItem
    {
        return OrderItem::create(array_merge([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'unit_price' => (float) $product->price,
            'total' => (float) $product->price,
        ], $overrides));
    }

    private function makeTemplate(string $name, string $subject, string $body): EmailTemplate
    {
        // 2026_10_22_000001 backfills both renewal templates, so a fresh test
        // database already holds these rows: take ownership of the content
        // instead of colliding on the unique name.
        return EmailTemplate::updateOrCreate(
            ['name' => $name],
            ['subject' => $subject, 'body' => $body, 'status' => 'active'],
        );
    }
}
