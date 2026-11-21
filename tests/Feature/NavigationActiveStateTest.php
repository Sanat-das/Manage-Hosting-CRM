<?php

namespace Tests\Feature;

use App\Support\AdminLte\ActiveRouteFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationActiveStateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve $routeName as the current route, transform every item of the real
     * menu through ActiveRouteFilter and return the route names of the active
     * leaf items (treeview parents are ignored deliberately — they light up
     * merely because a child is active).
     *
     * @return array<int, string>
     */
    private function activeRouteItemsFor(string $routeName): array
    {
        $path = '/verify-'.str_replace(['.', '/'], '-', $routeName);
        Route::get($path, fn () => 'x')->name($routeName);
        $this->get($path);

        $filter = $this->app->make(ActiveRouteFilter::class);
        $active = [];

        foreach (config('adminlte.menu', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $this->collectActiveLeaves($filter->transform($item) ?? [], $active);
        }

        return $active;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, string>  $active
     */
    private function collectActiveLeaves(array $item, array &$active): void
    {
        if (isset($item['submenu']) && is_array($item['submenu'])) {
            foreach ($item['submenu'] as $child) {
                if (is_array($child)) {
                    $this->collectActiveLeaves($child, $active);
                }
            }

            return;
        }

        if (empty($item['active']) || ! isset($item['route'])) {
            return;
        }

        $route = $item['route'];
        $active[] = is_array($route) ? (string) ($route[0] ?? '') : (string) $route;
    }

    public function test_sibling_action_pages_have_exactly_one_active_item(): void
    {
        $expected = [
            'admin.chat.satisfaction' => 'admin.chat.satisfaction',
            'admin.domains.search' => 'admin.domains.search',
            'admin.orders.create' => 'admin.orders.create',
            'admin.customers.create' => 'admin.customers.create',
            'admin.users.create' => 'admin.users.index',
        ];

        foreach ($expected as $current => $activeRoute) {
            $this->assertSame(
                [$activeRoute],
                $this->activeRouteItemsFor($current),
                "Unexpected active set on {$current}"
            );
        }
    }

    public function test_users_create_lights_the_users_menu_item(): void
    {
        $this->assertSame(
            ['admin.users.index'],
            $this->activeRouteItemsFor('admin.users.create')
        );
    }

    public function test_top_level_admin_pages_have_at_least_one_active_item(): void
    {
        $routes = [
            'admin.dashboard',
            'admin.quotes.index',
            'admin.settings.index',
            'admin.analytics.index',
            'admin.users.index',
        ];

        foreach ($routes as $route) {
            $this->assertNotEmpty(
                $this->activeRouteItemsFor($route),
                "Expected an active item on {$route}"
            );
        }
    }
}
