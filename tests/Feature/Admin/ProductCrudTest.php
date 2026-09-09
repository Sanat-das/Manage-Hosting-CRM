<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin product CRUD — the store / update / destroy half of the Manage Product
 * workflow (the option-link half is covered by ProductOptionLinkAdminTest).
 *
 * Guards the three defects the workflow shipped with:
 *  - the create form defaulted every visibility flag ON (only_admin included),
 *    and a cleared checkbox silently re-checked itself after a validation
 *    error because an unchecked box submits nothing;
 *  - a default billing cycle with no price saved silently as price 0 — an
 *    active, orderable product costing nothing;
 *  - deletion is refused while active or pending orders reference the product.
 */
class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        return $this->actingAsRole('admin', 'products.view', 'products.create', 'products.edit', 'products.delete');
    }

    /**
     * Sign in as a panel user holding exactly the given permissions.
     *
     * The gate tests must use a non-admin panel role: User::hasPermission()
     * short-circuits to true for admins, so an admin can never fail a
     * permission check no matter what the role is granted.
     */
    private function actingAsRole(string $roleName, string ...$permissions): User
    {
        $user = User::factory()->create();

        $role = Role::firstOrCreate(['name' => $roleName], ['label' => ucfirst($roleName)]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['label' => ucwords(str_replace('.', ' ', $name))]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->assignRole($roleName);
        $this->actingAs($user);

        return $user;
    }

    /**
     * A complete, valid payload in the shape the form submits: every boolean
     * flag posts an explicit 0 or 1 (hidden input + checkbox).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business cPanel Hosting',
            'billing_cycle' => 'monthly',
            'payment_type' => 'recurring',
            'provisioning_module' => 'manual',
            'status' => 'active',
            'gst_type' => 'standard',
            'quantity_behaviour' => 'multiple_services',
            'sort_order' => 5,
            'require_domain' => '1',
            'show_in_order' => '1',
            'show_in_affiliate' => '0',
            'only_admin' => '0',
            'require_public_ip' => '0',
            'require_private_ip' => '0',
            'is_bundle' => '0',
            'pricing' => [
                'monthly' => ['price' => '499.00', 'setup_fee' => '99.00'],
                'annual' => ['price' => '4990.00', 'setup_fee' => '0'],
            ],
        ], $overrides);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $product = Product::create(array_merge([
            'name' => 'Shared Hosting Basic',
            'price' => 100.00,
            'billing_cycle' => 'monthly',
            'provisioning_module' => 'manual',
            'show_in_order' => true,
            'status' => 'active',
        ], $overrides));

        $product->pricing()->create([
            'billing_cycle' => 'monthly',
            'price' => 100.00,
            'setup_fee' => 0,
        ]);

        return $product->refresh();
    }

    // ─────────────────────────────── Store ────────────────────────────────

    public function test_store_creates_the_product_its_ladder_and_mirrors_the_default_cycle(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('admin.products.store'), $this->payload());

        $product = Product::where('name', 'Business cPanel Hosting')->firstOrFail();

        $response->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHas('success');

        $this->assertSame('monthly', $product->billing_cycle);
        $this->assertSame('recurring', $product->payment_type);
        $this->assertSame(5, $product->sort_order);

        // The legacy price / setup_fee columns mirror the default cycle.
        $this->assertSame('499.00', (string) $product->price);
        $this->assertSame('99.00', (string) $product->setup_fee);

        // Both submitted cycles are stored — plus the `free` row savePricing()
        // always writes, priced at 0.
        $this->assertSame(
            ['annual', 'free', 'monthly'],
            $product->pricing->pluck('billing_cycle')->sort()->values()->all()
        );
        $this->assertDatabaseHas('product_pricing', [
            'product_id' => $product->id,
            'billing_cycle' => 'annual',
            'price' => 4990.00,
        ]);
    }

    public function test_store_persists_each_boolean_flag_as_submitted(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), $this->payload([
            'require_domain' => '1',
            'show_in_order' => '0',
            'only_admin' => '1',
            'is_bundle' => '0',
        ]))->assertRedirect();

        $product = Product::where('name', 'Business cPanel Hosting')->firstOrFail();

        $this->assertTrue((bool) $product->require_domain);
        $this->assertFalse((bool) $product->show_in_order);
        $this->assertTrue((bool) $product->only_admin);
        $this->assertFalse((bool) $product->is_bundle);
    }

    public function test_create_form_only_defaults_show_in_order_on(): void
    {
        $this->actingAsAdmin();

        $html = $this->get(route('admin.products.create'))->assertOk()->getContent();

        // show_in_order is the one flag a new product starts with.
        $this->assertMatchesRegularExpression(
            '/id="show_in_order"[^>]*checked/',
            $html,
            'The order-form visibility flag should default to on.'
        );

        foreach (['require_domain', 'show_in_affiliate', 'only_admin', 'require_public_ip', 'require_private_ip', 'is_bundle'] as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/id="'.$field.'"[^>]*checked/',
                $html,
                "The {$field} flag should not default to on."
            );

            // The hidden 0 is what makes an unchecked box survive a redisplay.
            $this->assertStringContainsString(
                '<input type="hidden" name="'.$field.'" value="0">',
                $html
            );
        }
    }

    public function test_a_cleared_flag_stays_cleared_when_the_form_is_redisplayed(): void
    {
        $this->actingAsAdmin();

        // A payload that fails validation (no name) with only_admin cleared.
        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->payload([
                'name' => '',
                'only_admin' => '0',
                'show_in_order' => '0',
            ]))
            ->assertSessionHasErrors('name');

        $html = $this->get(route('admin.products.create'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="only_admin"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="show_in_order"[^>]*checked/', $html);
    }

    public function test_store_rejects_a_default_billing_cycle_with_no_price(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.products.create'))
            ->post(route('admin.products.store'), $this->payload([
                'billing_cycle' => 'monthly',
                // Monthly toggled off on the Pricing tab: its inputs are
                // disabled, so only the annual row is submitted.
                'pricing' => ['annual' => ['price' => '4990.00', 'setup_fee' => '0']],
            ]))
            ->assertRedirect(route('admin.products.create'))
            ->assertSessionHasErrors('billing_cycle');

        $this->assertDatabaseMissing('products', ['name' => 'Business cPanel Hosting']);
    }

    public function test_store_accepts_a_zero_priced_default_cycle(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.products.store'), $this->payload([
            'pricing' => ['monthly' => ['price' => '0', 'setup_fee' => '0']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            '0.00',
            (string) Product::where('name', 'Business cPanel Hosting')->firstOrFail()->price,
            'An explicit zero price is a deliberate choice, not the silent fallback.'
        );
    }

    public function test_store_exempts_free_products_from_the_default_cycle_price(): void
    {
        $this->actingAsAdmin();

        // A free product prices the `free` cycle only, but products.billing_cycle
        // cannot hold 'free' — it must stay one of the DEFAULT_CYCLES.
        $this->post(route('admin.products.store'), $this->payload([
            'name' => 'Free Starter',
            'payment_type' => 'free',
            'billing_cycle' => 'monthly',
            'pricing' => ['free' => ['price' => '0', 'setup_fee' => '0']],
        ]))->assertSessionHasNoErrors();

        $product = Product::where('name', 'Free Starter')->firstOrFail();

        $this->assertSame('free', $product->payment_type);
        $this->assertSame(['free'], $product->pricing->pluck('billing_cycle')->all());
    }

    public function test_store_requires_the_create_permission(): void
    {
        $this->actingAsRole('support', 'products.view');

        $this->post(route('admin.products.store'), $this->payload())->assertForbidden();

        $this->assertDatabaseMissing('products', ['name' => 'Business cPanel Hosting']);
    }

    // ─────────────────────────────── Update ───────────────────────────────

    public function test_edit_form_mirrors_the_stored_flags_and_guards_unsaved_changes(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['only_admin' => true, 'show_in_order' => false]);

        $html = $this->get(route('admin.products.edit', $product))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="only_admin"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="show_in_order"[^>]*checked/', $html);

        foreach (['require_domain', 'show_in_order', 'only_admin', 'is_bundle'] as $field) {
            $this->assertStringContainsString(
                '<input type="hidden" name="'.$field.'" value="0">',
                $html
            );
        }

        $this->assertStringContainsString('beforeunload', $html, 'The edit form warns before discarding unsaved changes.');
    }

    public function test_update_saves_the_product_and_replaces_the_ladder(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        $this->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => 'Shared Hosting Pro',
                'billing_cycle' => 'annual',
                'active_tab' => 'pricing',
                'pricing' => [
                    'annual' => ['price' => '5990.00', 'setup_fee' => '0'],
                ],
            ]))
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHas('success')
            ->assertSessionHas('active_tab', 'pricing');

        $product->refresh()->load('pricing');

        $this->assertSame('Shared Hosting Pro', $product->name);
        $this->assertSame('annual', $product->billing_cycle);
        $this->assertSame('5990.00', (string) $product->price);

        // The monthly row the product was created with is gone (`free` is the
        // always-written row, not a submitted one).
        $this->assertSame(
            ['annual', 'free'],
            $product->pricing->pluck('billing_cycle')->sort()->values()->all()
        );
    }

    public function test_update_can_clear_a_boolean_flag(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct(['only_admin' => true, 'require_domain' => true]);

        $this->put(route('admin.products.update', $product), $this->payload([
            'name' => $product->name,
            'only_admin' => '0',
            'require_domain' => '0',
        ]))->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertFalse((bool) $product->only_admin);
        $this->assertFalse((bool) $product->require_domain);
    }

    public function test_update_rejects_a_default_billing_cycle_with_no_price_and_persists_nothing(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        $this->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), $this->payload([
                'name' => 'Renamed Under A Bad Cycle',
                'billing_cycle' => 'quarterly',
                'pricing' => ['monthly' => ['price' => '150.00', 'setup_fee' => '0']],
            ]))
            ->assertSessionHasErrors('billing_cycle');

        $product->refresh()->load('pricing');

        $this->assertSame('Shared Hosting Basic', $product->name);
        $this->assertSame('monthly', $product->billing_cycle);
        $this->assertSame('100.00', (string) $product->price);
        $this->assertSame(['monthly'], $product->pricing->pluck('billing_cycle')->all());
    }

    public function test_update_requires_the_edit_permission(): void
    {
        $product = $this->makeProduct();
        $this->actingAsRole('support', 'products.view');

        $this->put(route('admin.products.update', $product), $this->payload())->assertForbidden();

        $this->assertSame('Shared Hosting Basic', $product->fresh()->name);
    }

    // ─────────────────────────────── Destroy ──────────────────────────────

    public function test_destroy_removes_the_product_and_its_pricing(): void
    {
        $this->actingAsAdmin();
        $product = $this->makeProduct();

        $this->from(route('admin.products.index'))
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_pricing', ['product_id' => $product->id]);
    }

    public function test_destroy_is_blocked_while_live_orders_reference_the_product(): void
    {
        $this->actingAsAdmin();

        foreach (['active', 'pending'] as $index => $status) {
            $product = $this->makeProduct(['name' => "Ordered Product {$status}"]);

            Order::create([
                'customer_id' => 1,
                'product_id' => $product->id,
                'order_number' => 'ORD-'.($index + 1),
                'quantity' => 1,
                'total' => 100.00,
                'status' => $status,
            ]);

            $this->from(route('admin.products.index'))
                ->delete(route('admin.products.destroy', $product))
                ->assertRedirect(route('admin.products.index'))
                ->assertSessionHas('error');

            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    public function test_destroy_requires_the_delete_permission(): void
    {
        $product = $this->makeProduct();
        $this->actingAsRole('support', 'products.view', 'products.edit');

        $this->delete(route('admin.products.destroy', $product))->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
