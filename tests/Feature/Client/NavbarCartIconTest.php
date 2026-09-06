<?php

namespace Tests\Feature\Client;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store cart icon in the navbar (AdminLTE header partial) is rendered
 * only for client-portal users, and shows a badge with the total quantity
 * held in the session cart.
 */
class NavbarCartIconTest extends TestCase
{
    use RefreshDatabase;

    private function makeClientUser(): Customer
    {
        $user = User::factory()->create(['role' => 'client']);

        return Customer::create([
            'user_id' => $user->id,
            'company' => 'Navbar Corp',
            'status' => 'active',
        ]);
    }

    public function test_client_navbar_shows_cart_icon_without_badge_when_cart_is_empty(): void
    {
        $customer = $this->makeClientUser();

        $this->actingAs($customer->user)
            ->get(route('client.store.index'))
            ->assertOk()
            ->assertSee('aria-label="Cart"', false)
            ->assertDontSee('navbar-badge badge text-bg-primary', false);
    }

    public function test_client_navbar_badge_shows_total_quantity_from_session_cart(): void
    {
        $customer = $this->makeClientUser();

        $this->withSession(['cart' => [
            ['product_id' => 1, 'billing_cycle' => 'monthly', 'quantity' => 1],
            ['product_id' => 2, 'billing_cycle' => 'monthly', 'quantity' => 2],
        ]])
            ->actingAs($customer->user)
            ->get(route('client.store.index'))
            ->assertOk()
            ->assertSee('aria-label="Cart"', false)
            ->assertSee('<span class="navbar-badge badge text-bg-primary">3</span>', false);
    }

    public function test_client_navbar_badge_caps_at_99_plus(): void
    {
        $customer = $this->makeClientUser();

        $this->withSession(['cart' => [
            ['product_id' => 1, 'billing_cycle' => 'monthly', 'quantity' => 50],
            ['product_id' => 2, 'billing_cycle' => 'monthly', 'quantity' => 50],
            ['product_id' => 3, 'billing_cycle' => 'monthly', 'quantity' => 50],
        ]])
            ->actingAs($customer->user)
            ->get(route('client.store.index'))
            ->assertOk()
            ->assertSee('<span class="navbar-badge badge text-bg-primary">99+</span>', false);
    }

    public function test_admin_navbar_does_not_show_cart_icon(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.profile'))
            ->assertOk()
            ->assertDontSee('aria-label="Cart"', false)
            ->assertDontSee('navbar-badge badge text-bg-primary', false);
    }
}