<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use ColorlibHQ\AdminLte\AdminLte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ActiveRouteFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve $routeName as the current route, then build the REAL menu from
     * config through the full filter pipeline and report the Orders group.
     */
    private function ordersStateFor(string $routeName): array
    {
        // Resolve the target route name so ActiveFilter sees it as current.
        Route::get('/verify-'.str_replace(['.', '/'], '-', $routeName), fn () => 'x')->name($routeName);
        $this->get('/verify-'.str_replace(['.', '/'], '-', $routeName));

        // Fresh AdminLte instance over the real config menu.
        $adminlte = new AdminLte(
            config('adminlte.menu'),
            app('events'),
            app()
        );

        $state = ['parent' => false, 'all' => false, 'new' => false];
        foreach ($adminlte->menu('sidebar') as $item) {
            if (($item['text'] ?? '') !== 'Orders' || ! isset($item['submenu'])) {
                continue;
            }
            $state['parent'] = ! empty($item['active']);
            foreach ($item['submenu'] as $child) {
                if ($child['text'] === 'All Orders') {
                    $state['all'] = ! empty($child['active']);
                }
                if ($child['text'] === 'New Order') {
                    $state['new'] = ! empty($child['active']);
                }
            }
        }

        return $state;
    }

    private function actingAsOrdersViewAdmin(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $role = Role::firstOrCreate(['name' => 'admin'], ['label' => 'Administrator']);
        $role->permissions()->sync(
            Permission::firstOrCreate(['name' => 'orders.view'], ['label' => 'View Orders'])
        );
        $user->roles()->sync([$role->id]);
        $this->actingAs($user);
    }

    public function test_index_highlights_all_orders_and_opens_parent(): void
    {
        $this->actingAsOrdersViewAdmin();
        $state = $this->ordersStateFor('admin.orders.index');

        $this->assertTrue($state['all'], 'All Orders should be active on admin.orders.index');
        $this->assertFalse($state['new'], 'New Order should not be active on index');
        $this->assertTrue($state['parent'], 'Orders treeview parent should open');
    }

    public function test_show_keeps_all_orders_active(): void
    {
        $this->actingAsOrdersViewAdmin();
        $state = $this->ordersStateFor('admin.orders.show');
        $this->assertTrue($state['all']);
        $this->assertFalse($state['new']);
        $this->assertTrue($state['parent']);
    }

    public function test_create_highlights_new_order_only(): void
    {
        $this->actingAsOrdersViewAdmin();
        $state = $this->ordersStateFor('admin.orders.create');
        $this->assertTrue($state['new'], 'New Order should be active on create');
        $this->assertFalse($state['all'], 'All Orders should not be active on create');
        $this->assertTrue($state['parent']);
    }

    public function test_unrelated_route_highlights_nothing(): void
    {
        $this->actingAsOrdersViewAdmin();
        $state = $this->ordersStateFor('admin.invoices.index');
        $this->assertFalse($state['all']);
        $this->assertFalse($state['new']);
        $this->assertFalse($state['parent']);
    }
}