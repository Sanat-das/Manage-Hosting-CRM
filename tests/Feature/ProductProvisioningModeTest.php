<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingAccount;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductModule;
use App\Models\ProvisioningEvent;
use App\Models\Role;
use App\Models\ServiceInstance;
use App\Models\User;
use App\Services\HostingService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Details-tab "Provisioning module" dropdown offers each builtin as two
 * options — Auto and Manual — so the mode is chosen in the same place as the
 * module.
 *
 * Manual means no provisioning on order: the order activates with a pending
 * hosting account and the VM is built later from the hosting page, with the
 * product's template selection. This mirrors the long-standing Hyper-V manual
 * flow for every compute module.
 */
final class ProductProvisioningModeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $ids = [];

        foreach (['products.view', 'products.create', 'products.edit'] as $name) {
            $ids[] = Permission::firstOrCreate(['name' => $name], ['label' => $name])->id;
        }

        $role->permissions()->sync($ids);
        $user->assignRole('admin');
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Plan '.uniqid(),
            'billing_cycle' => 'monthly',
            'payment_type' => 'recurring',
            'provisioning_module' => 'proxmox|manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'quantity_behaviour' => 'multiple_services',
            'pricing' => ['monthly' => ['price' => '50.00', 'setup_fee' => '0']],
        ], $overrides);
    }

    // ───────────────────────── form + save plumbing ─────────────────────────

    public function test_creating_with_a_manual_selection_stores_the_slug_and_sets_the_link_mode(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('admin.products.store'), $this->payload([
            'name' => 'Proxmox Manual Plan',
            'provisioning_module' => 'proxmox|manual',
        ]));

        $product = Product::where('name', 'Proxmox Manual Plan')->firstOrFail();

        $response->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('success');

        // The product row keeps the plain slug; the mode lives on the link.
        $this->assertSame('proxmox', $product->provisioning_module);

        $link = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->sole();
        $this->assertTrue((bool) $link->enabled);
        $this->assertSame(ProductModule::PROVISIONING_MODE_MANUAL, $link->provisioning_mode);
    }

    public function test_creating_with_an_auto_selection_sets_the_link_to_auto(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), $this->payload([
            'name' => 'Proxmox Auto Plan',
            'provisioning_module' => 'proxmox|auto',
        ]))->assertSessionHas('success');

        $product = Product::where('name', 'Proxmox Auto Plan')->firstOrFail();
        $link = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->sole();

        $this->assertSame(ProductModule::PROVISIONING_MODE_AUTO, $link->provisioning_mode);
    }

    public function test_updating_the_selection_switches_the_link_mode(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), $this->payload([
            'name' => 'Switchable Plan',
            'provisioning_module' => 'proxmox|auto',
        ]))->assertSessionHas('success');

        $product = Product::where('name', 'Switchable Plan')->firstOrFail();

        $this->put(route('admin.products.update', $product), $this->payload([
            'name' => 'Switchable Plan',
            'provisioning_module' => 'proxmox|manual',
        ]))->assertSessionHas('success');

        $link = ProductModule::where('product_id', $product->id)->where('module_slug', 'proxmox')->sole();
        $this->assertSame(ProductModule::PROVISIONING_MODE_MANUAL, $link->provisioning_mode);
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), $this->payload([
            'provisioning_module' => 'proxmox|bogus',
        ]))->assertSessionHasErrors('provisioning_mode');

        $this->assertSame(0, Product::where('name', 'like', 'Plan %')->count());
    }

    public function test_a_legacy_plain_slug_preserves_the_stored_mode(): void
    {
        $this->actingAsAdmin();

        // A product created before the dual options existed.
        $product = Product::create([
            'name' => 'Legacy Hyperv',
            'price' => 50,
            'provisioning_module' => 'hyperv',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'hyperv',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_AUTO,
            'config' => [],
        ]);

        // Re-save without the encoded selection: the stored auto mode must
        // survive instead of snapping back to the hyperv default (manual).
        $this->put(route('admin.products.update', $product), $this->payload([
            'name' => 'Legacy Hyperv',
            'provisioning_module' => 'hyperv',
        ]))->assertSessionHas('success');

        $link = ProductModule::where('product_id', $product->id)->where('module_slug', 'hyperv')->sole();
        $this->assertSame(ProductModule::PROVISIONING_MODE_AUTO, $link->provisioning_mode);
    }

    // ─────────────────────────────── rendering ───────────────────────────────

    public function test_the_forms_offer_auto_and_manual_for_every_builtin(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('value="proxmox|auto"', false)
            ->assertSee('value="proxmox|manual"', false)
            ->assertSee('value="hyperv|auto"', false)
            ->assertSee('value="hyperv|manual"', false)
            ->assertSee('value="cpanel|manual"', false)
            ->assertSee('Manual (no module)', false);
    }

    public function test_the_edit_page_selects_the_stored_mode(): void
    {
        $this->actingAsAdmin();

        $product = Product::create([
            'name' => 'Selected Mode',
            'price' => 50,
            'provisioning_module' => 'proxmox',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => [],
        ]);

        $this->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('value="proxmox|manual"', false)
            ->assertSee('Proxmox VE — Manual', false);
    }

    public function test_the_show_page_displays_the_mode(): void
    {
        $this->actingAsAdmin();

        $product = Product::create([
            'name' => 'Shown Mode',
            'price' => 50,
            'provisioning_module' => 'proxmox',
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);
        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => [],
        ]);

        $this->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('text-bg-warning ms-1">Manual</span>', false);
    }

    // ───────────────────────── manual order behaviour ─────────────────────────

    /**
     * A manual Proxmox product must behave like Hyper-V manual: the paid order
     * activates with a pending hosting account, no ServiceInstance and no host
     * call — the VM is built later from the hosting page.
     */
    public function test_a_manual_proxmox_product_activates_with_pending_hosting_and_no_host_calls(): void
    {
        Http::fake();

        $order = $this->makeManualProxmoxPaidOrder();

        $result = app(OrderService::class)->advanceAfterPayment($order);

        $this->assertSame(Order::STATUS_ACTIVE, $result->fresh()->status);

        $hosting = HostingAccount::where('order_id', $order->id)->sole();
        $this->assertSame(HostingService::STATUS_PENDING, $hosting->status);
        $this->assertSame(0, ServiceInstance::where('order_id', $order->id)->count());

        Http::assertNothingSent();

        $event = ProvisioningEvent::where('event_type', 'provision')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('pending', $event->event_status);
        $this->assertSame('awaiting_manual_vm', $event->payload['reason'] ?? null);
        $this->assertSame('proxmox', $event->payload['module'] ?? null);
        $this->assertNull($event->service_instance_id);
    }

    private function makeManualProxmoxPaidOrder(): Order
    {
        $group = ProductGroup::create([
            'name' => 'VPS '.uniqid(),
            'slug' => 'vps-'.uniqid(),
            'is_hosting' => true,
            'status' => 'active',
        ]);

        $product = Product::create([
            'name' => 'Proxmox Manual '.uniqid(),
            'price' => 50,
            'provisioning_module' => 'proxmox',
            'product_group_id' => $group->id,
            'billing_cycle' => 'monthly',
            'status' => 'active',
        ]);

        ProductModule::create([
            'product_id' => $product->id,
            'module_slug' => 'proxmox',
            'enabled' => true,
            'provisioning_mode' => ProductModule::PROVISIONING_MODE_MANUAL,
            'config' => ['cpu' => 2, 'ram' => 2048, 'disk' => 50],
        ]);

        $customer = Customer::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
        ]);

        $order = Order::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'order_number' => 'ORD-'.uniqid(),
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'total' => 50,
            'status' => Order::STATUS_PENDING,
            'domain_name' => 'example.test',
        ]);

        return app(OrderService::class)->markPaid($order);
    }
}
