<?php

namespace Tests\Dusk;

use App\Models\Customer;
use App\Models\GstSetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductAddonPricing;
use App\Models\ProductGroup;
use App\Models\ProductPricing;
use App\Models\Role;
use App\Models\User;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Real-browser proof that an order carrying an add-on can be placed through
 * the admin New Order UI: pick a product, tick its add-on checkbox, submit,
 * and the persisted order has the parent + add-on rows with a matching
 * draft invoice.
 *
 * Same runtime contract as AdminOrderCreateDuskTest: the app must be served
 * with `php artisan serve --env=dusk` so the browser and the test process
 * share database/dusk.sqlite. The schema is migrated fresh once per process;
 * setUp deletes only this test's fixtures by stable identifiers.
 */
class AddOnOrderDuskTest extends DuskTestCase
{
    private static bool $schemaMigrated = false;

    private User $admin;

    private Product $vps;

    private ProductAddon $addon;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$schemaMigrated) {
            $this->artisan('migrate:fresh', ['--force' => true]);
            self::$schemaMigrated = true;
        }

        $this->removeFixtureRows();

        GstSetting::where('id', 1)->update([
            'state_code' => '27',
            'state_name' => 'Maharashtra',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
            'enabled' => 1,
            'tax_mode' => GstSetting::TAX_MODE_GLOBAL,
        ]);

        $category = ProductGroup::create([
            'name' => 'Dusk Addon Cloud',
            'slug' => 'dusk-addon-cloud',
            'status' => 'active',
            'is_hosting' => true,
        ]);

        $this->vps = Product::create([
            'name' => 'Dusk Addon VPS',
            'product_group_id' => $category->id,
            'price' => 299.00,
            'billing_cycle' => 'monthly',
            'show_in_order' => true,
            'only_admin' => false,
            'status' => 'active',
            'require_domain' => false,
        ]);

        ProductPricing::create([
            'product_id' => $this->vps->id,
            'billing_cycle' => 'monthly',
            'price' => 299.00,
            'setup_fee' => 0,
        ]);

        // Global add-on: billed on the parent's monthly cycle at its base
        // price (the annual matrix row only proves the fallback path stays
        // own-cycle when no row matches — unused in the monthly flow).
        $this->addon = ProductAddon::create([
            'product_id' => null,
            'name' => 'Dusk Addon Support',
            'description' => 'Priority support add-on for the Dusk test.',
            'billing_cycle' => 'monthly',
            'setup_fee' => 0,
            'price' => 99.00,
            'status' => 'active',
        ]);

        ProductAddonPricing::create([
            'product_addon_id' => $this->addon->id,
            'billing_cycle' => 'annual',
            'price' => 990.00,
            'setup_fee' => 0,
        ]);

        $this->admin = User::factory()->create([
            'email' => 'dusk-addon-admin@example.com',
            'role' => 'admin',
        ]);

        $adminRole = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        foreach (['dashboard.view', 'orders.view', 'orders.create', 'orders.edit', 'customers.create'] as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                ['label' => ucwords(str_replace('.', ' ', $permissionName))]
            );
            $adminRole->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->admin->assignRole('admin');

        $clientUser = User::factory()->create([
            'email' => 'dusk-addon-customer@example.com',
            'role' => 'client',
        ]);
        $this->customer = Customer::create([
            'user_id' => $clientUser->id,
            'company' => 'Dusk Addon Corp',
            'state_code' => '27',
            'status' => 'active',
        ]);
    }

    private function removeFixtureRows(): void
    {
        $userIds = User::whereIn('email', [
            'dusk-addon-admin@example.com',
            'dusk-addon-customer@example.com',
        ])->pluck('id');
        Customer::whereIn('user_id', $userIds)->delete();
        User::whereIn('id', $userIds)->delete();

        $productIds = Product::where('name', 'Dusk Addon VPS')->pluck('id');
        Order::whereIn('product_id', $productIds)->delete();
        Product::whereIn('id', $productIds)->delete();

        ProductAddon::where('name', 'Dusk Addon Support')->delete();
        ProductGroup::where('name', 'Dusk Addon Cloud')->delete();

        GstSetting::where('id', 1)->update(['enabled' => 0, 'tax_mode' => 'global']);
    }

    public function test_admin_places_an_order_with_an_addon_through_the_ui(): void
    {
        $vpsId = $this->vps->id;
        $addonId = $this->addon->id;
        $customerId = $this->customer->id;

        $this->browse(function (Browser $browser) use ($vpsId, $customerId) {
            $browser->visit('/login')
                ->type('email', 'dusk-addon-admin@example.com')
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->assertPresent('#order-lines .order-line')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '".$vpsId."';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            // Catalog price fills in and the add-on picker renders its row.
            $browser->waitUntil('document.querySelector("#order-lines .order-line .line-price") !== null && document.querySelector("#order-lines .order-line .line-price").value === "299.00"')
                ->waitUntil('document.querySelector("#order-lines .line-addon-check") !== null')
                ->assertSee('Dusk Addon Support')
                ->assertSee('₹99.00');

            // Tick the add-on for real — its qty box must enable.
            $browser->click('#order-lines .order-line:nth-of-type(1) .line-addon-check')
                ->waitUntil('document.querySelector("#order-lines .order-line:nth-of-type(1) .line-addon-qty").disabled === false');

            $browser->select('#customer_id', (string) $customerId)
                ->check('#generate-invoice')
                ->press('Create Order')
                ->waitUntil('window.location.pathname.match(/^\/admin\/orders\/\\d+$/) !== null')
                ->screenshot('addon-order-placed');
        });

        $order = Order::query()
            ->whereHas('customer.user', fn ($user) => $user->where('email', 'dusk-addon-customer@example.com'))
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('398.00', (string) $order->total, 'Parent 299 + add-on 99.');
        $this->assertCount(2, $order->items);

        $parent = $order->items->firstWhere('product_addon_id', null);
        $addonRow = $order->items->firstWhere('product_addon_id', $addonId);

        $this->assertNotNull($parent, 'Parent line exists.');
        $this->assertNotNull($addonRow, 'Add-on line exists.');
        $this->assertSame($parent->id, $addonRow->parent_item_id);
        $this->assertSame('monthly', $addonRow->billing_cycle, 'Parent is monthly; add-on billed monthly at its base price.');
        $this->assertSame('99.00', (string) $addonRow->unit_price);

        $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('398.00', (string) $invoice->amount);
        $this->assertCount(2, $invoice->items);
        $this->assertSame(398.00, round((float) $invoice->items->sum('total'), 2));
    }

    /**
     * The live Order Summary must preview what the persisted order bills:
     * ticking an add-on adds its resolved charge to the subtotal and the GST
     * estimate, unticking removes it again.
     */
    public function test_admin_order_preview_includes_checked_addon_in_totals(): void
    {
        $vpsId = $this->vps->id;
        $customerId = $this->customer->id;

        $this->browse(function (Browser $browser) use ($vpsId, $customerId) {
            $browser->visit('/login')
                ->type('email', 'dusk-addon-admin@example.com')
                ->type('password', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin/dashboard');

            $browser->visit('/admin/orders/create')
                ->waitFor('#order-lines')
                ->assertPresent('#order-lines .order-line')
                ->script("
                    const productSelect = document.querySelector('#order-lines .order-line:nth-of-type(1) .line-product');
                    productSelect.value = '".$vpsId."';
                    productSelect.dispatchEvent(new Event('change', { bubbles: true }));
                ");

            // Parent line alone: 299 + 18% GST = 352.82.
            $browser->waitUntil('document.querySelector("#order-lines .order-line .line-price") !== null && document.querySelector("#order-lines .order-line .line-price").value === "299.00"')
                ->waitUntil('document.querySelector("#order-lines .order-line .line-addon-check") !== null')
                ->waitUntil('document.getElementById("gst-total").textContent === "₹352.82"');

            $browser->select('#customer_id', (string) $customerId);

            // Ticking the 99.00 add-on brings the summary in line with the
            // invoice: 398.00 + 18% GST = 469.64.
            $browser->click('#order-lines .order-line:nth-of-type(1) .line-addon-check')
                ->waitUntil('document.getElementById("gst-total").textContent === "₹469.64"');

            $subtotal = $browser->script('return document.getElementById("gst-subtotal").textContent;');
            $this->assertSame('₹398.00', $subtotal[0]);

            // Unticking drops the add-on from the preview again.
            $browser->click('#order-lines .order-line:nth-of-type(1) .line-addon-check')
                ->waitUntil('document.getElementById("gst-total").textContent === "₹352.82"');
        });
    }
}
