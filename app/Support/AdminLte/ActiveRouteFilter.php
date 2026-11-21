<?php

namespace App\Support\AdminLte;

use ColorlibHQ\AdminLte\Menu\Filters\FilterInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;

/**
 * Marks menu items as active based on the current route name.
 *
 * The package's stock ActiveFilter only knows how to light up items defined
 * with a literal `url` key. This app's whole sidebar (config/adminlte.php)
 * is built from named `route` keys instead, so the stock filter never matches
 * and no menu item ever receives the `active` class.
 *
 * This filter replaces it. It keeps the stock behavior for `url`-based and
 * explicit `active`-pattern items, and adds route-name matching:
 *
 *   - Exact route match lights the item.
 *   - A *listing* item (route action `index`/`show`) is also kept active for
 *     sibling routes in the same resource group, but ONLY when that sibling
 *     has no menu item of its own (its route name is not declared anywhere in
 *     the menu). This keeps the current module open while walking a resource,
 *     yet lets an explicitly declared sibling — e.g. admin.orders.create or
 *     admin.chat.satisfaction — win outright, so exactly one leaf is active.
 *
 * Submenu parents become active when any of their children is active, which
 * is what opens the parent treeview group.
 */
class ActiveRouteFilter implements FilterInterface
{
    /**
     * Action suffixes that mark an item as the "resource listing", so it also
     * matches sibling detail routes inside the same resource group.
     *
     * @var array<int, string>
     */
    private const LISTING_ACTIONS = ['index', 'show'];

    /**
     * Every route name explicitly declared anywhere in the menu tree, keyed by
     * name for O(1) lookup. Built once in the constructor from the live config
     * (including module-appended items).
     *
     * @var array<string, true>
     */
    private array $explicitRoutes;

    public function __construct(Repository $config)
    {
        $this->explicitRoutes = [];
        $this->collectExplicitRoutes((array) $config->get('adminlte.menu', []));
    }

    /**
     * Recursively collect every non-null string route name from the menu tree.
     * Array-form routes ([name, params]) contribute their first element.
     *
     * @param  array<int|string, mixed>  $items
     */
    private function collectExplicitRoutes(array $items): void
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['route'])) {
                $name = $this->routeName($item['route']);
                if ($name !== null && $name !== '') {
                    $this->explicitRoutes[$name] = true;
                }
            }

            if (isset($item['submenu']) && is_array($item['submenu'])) {
                $this->collectExplicitRoutes($item['submenu']);
            }
        }
    }

    /**
     * Normalize a menu `route` value (string or [name, params]) to its name.
     */
    private function routeName(mixed $route): ?string
    {
        if (is_string($route)) {
            return $route;
        }

        if (is_array($route) && isset($route[0]) && is_string($route[0])) {
            return $route[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function transform(array $item): ?array
    {
        if (isset($item['submenu'])) {
            $item['submenu'] = array_map(
                fn (array $child) => $this->transform($child) ?? $child,
                $item['submenu']
            );

            // Parent is active when any child is (opens the treeview group).
            foreach ($item['submenu'] as $child) {
                if (! empty($child['active'])) {
                    $item['active'] = true;
                    break;
                }
            }
        }

        // Explicit boolean already set — respect it.
        if (isset($item['active']) && is_bool($item['active'])) {
            return $item;
        }

        // Explicit path patterns (e.g. ['admin/tickets/open*']) — URL matching.
        if (! empty($item['active']) && is_array($item['active'])) {
            $item['active'] = $this->matchesAnyUrl((array) $item['active']);
        } elseif (isset($item['route'])) {
            $item['active'] = $this->matchesRoute($item['route']);
        } elseif (isset($item['url']) && $item['url'] !== '#' && $item['url'] !== '/') {
            $patterns = [trim($item['url'], '/'), trim($item['url'], '/').'/*'];
            $item['active'] = $this->matchesAnyUrl($patterns);
        } elseif (($item['url'] ?? null) === '/') {
            $item['active'] = Request::path() === '/';
        } else {
            $item['active'] = false;
        }

        return $item;
    }

    /**
     * Match a menu item's declared route (string or [name, params] array)
     * against the current route name.
     *
     * @param  string|array<int, mixed>  $route
     */
    private function matchesRoute(string|array $route): bool
    {
        $routeName = $this->routeName($route);
        $current = Route::currentRouteName();

        if ($current === null || $routeName === null || $routeName === '') {
            return false;
        }

        // Exact match wins.
        if ($current === $routeName) {
            return true;
        }

        // Only the listing item extends into the resource group.
        $lastDot = strrpos($routeName, '.');
        if ($lastDot === false) {
            return false;
        }

        $group = substr($routeName, 0, $lastDot);
        $action = substr($routeName, $lastDot + 1);

        if (! in_array($action, self::LISTING_ACTIONS, true)) {
            return false;
        }

        // Current route must live inside the same resource group ...
        if (! str_starts_with($current, $group.'.')) {
            return false;
        }

        // ... and must not have its own menu item, which wins outright.
        return ! isset($this->explicitRoutes[$current]);
    }

    /**
     * Match the current request path against any of the given URL patterns
     * (same semantics as Laravel's Request::is wildcards).
     *
     * @param  array<int, mixed>  $patterns
     */
    private function matchesAnyUrl(array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '/' && Request::path() === '/') {
                return true;
            }
            if ($pattern !== '/' && Request::is((string) $pattern)) {
                return true;
            }
        }

        return false;
    }
}
